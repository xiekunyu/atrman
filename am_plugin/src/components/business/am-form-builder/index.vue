<template>
  <ElDrawer
    :model-value="visible"
    @update:model-value="handleCancel"
    :size="size"
    :with-header="false"
    body-class="form-builder-drawer"
  >
    <div class="form-builder-container">
      <!-- 工具栏 -->
      <div class="form-builder-toolbar">
        <div class="form-builder-title-wrap">
          <h3 class="form-builder-title">{{ title }}</h3>
        </div>
        <div class="form-builder-actions">
          <ElButton @click="handleCancel" v-if="showCancel" plain>取消</ElButton>
          <ElButton @click="handleSubmit" v-if="showSubmit" type="primary">保存</ElButton>
        </div>
      </div>

      <!-- 主内容区 -->
      <div class="form-builder-main">
        <!-- 左侧组件库 -->
        <div class="form-builder-sidebar">
          <div v-for="group in componentLibrary" :key="group.title" class="component-group">
            <h4 class="sidebar-title">{{ group.title }}</h4>
            <VueDraggable
              v-model="group.operation"
              :animation="150"
              ghost-class="sortable-ghost"
              :group="{ name: 'people', pull: 'clone', put: false }"
              :sort="false"
              :clone="addFormItem"
              class="component-list"
            >
              <div
                v-for="component in group.operation"
                :key="component.name"
                class="component-item"
              >
                <span class="component-icon">
                  <ArtSvgIcon :icon="component.icon" />
                </span>
                <span class="component-name">{{ component.name }}</span>
              </div>
            </VueDraggable>
          </div>
        </div>

        <!-- 右侧配置区 -->
        <div class="form-builder-content">
          <!-- 表单预览 -->
          <div class="form-preview">
            <div class="content-title preview-title">
              <div class="title-left">
                <b>表单预览</b>
              </div>
              <div class="preview-actions">
                <ElButton type="primary" @click="previewForm" v-if="showPreview" plain>预览表单</ElButton>
                <ElButton @click="exportConfig" v-if="showExport">导出配置</ElButton>
                <ElButton @click="importConfig" v-if="showImport">导入配置</ElButton>
              </div>
            </div>

            <div class="preview-container">
              <ElForm :model="formData" label-width="150px">
                <VueDraggable
                  v-model="formConfig.items"
                  item-key="key"
                  :animation="150"
                  ghost-class="sortable-ghost"
                  group="people"
                  class="draggable-container"
                >
                  <template v-if="formConfig.items.length > 0">
                    <div
                      class="form-item-container"
                      :class="{ selected: element.key === selectedItem?.key }"
                      @click.stop="selectFormItem(element)"
                      v-for="(element, index) in formConfig.items"
                      :key="element.key"
                    >
                      <ElFormItem
                        :label="element.name"
                        :prop="element.key"
                        :required="element.required"
                        class="preview-form-item"
                      >
                        <template #label>
                          <div class="preview-label">
                            <span class="mr-1">{{ element.name }}</span>
                            <ElTooltip v-if="element.tooltip" :content="element.tooltip" placement="top">
                              <ArtSvgIcon icon="ri:question-line" class="text-lg" />
                            </ElTooltip>
                          </div>
                        </template>
                        <Widget :element="element" :index="index" />
                      </ElFormItem>
                    </div>
                  </template>
                  <div v-else class="form-empty">
                    <ArtSvgIcon icon="ri:drag-drop-line" class="form-empty-icon" />
                    <span>从左侧拖拽来添加字段</span>
                  </div>
                </VueDraggable>
              </ElForm>
            </div>
          </div>

          <!-- 表单配置 -->
          <div class="form-config" v-if="selectedItem">
            <h4 class="content-title">
              <span class="title-left">配置项：{{ selectedItem.name }}</span>
              <ElButton
                type="danger"
                size="small"
                class="delete-item-btn"
                @click="deleteFormItem(selectedItem.key)"
              >
                删除
              </ElButton>
            </h4>

            <Options :selectedItem="selectedItem" :fieldList="fieldList" />
          </div>
          <div class="form-config form-config-empty" v-if="!selectedItem">
            <ElEmpty description="请选择表单项" />
          </div>
        </div>

        <!-- 预览对话框 -->
        <ElDialog v-model="previewVisible" title="表单预览" width="620px">
          <div>
            <AmForm
              v-model="previewFormData"
              :items="previewData.items"
              :rules="previewData.rules"
              :span="24"
              :gutter="20"
              label-width="150px"
              :show-submit="true"
              :show-reset="true"
              @submit="previewSubmit"
            />
          </div>
        </ElDialog>

        <!-- 导入配置对话框 -->
        <ElDialog v-model="importVisible" title="导入配置" width="600px">
          <ElInput
            type="textarea"
            v-model="importConfigStr"
            placeholder="请粘贴配置JSON"
            :rows="10"
          />
          <template #footer>
            <ElButton @click="importVisible = false">取消</ElButton>
            <ElButton type="primary" @click="doImportConfig">导入</ElButton>
          </template>
        </ElDialog>
      </div>
    </div>
  </ElDrawer>
</template>

<script setup lang="ts">
import { ref, computed, reactive, onMounted } from 'vue'
import ArtSvgIcon from '@/components/core/base/art-svg-icon/index.vue'
import componentLibrary from './componentLibrary'
import Widget from './widget/index.vue'
import Options from './options/index.vue'
import { FormItem } from './types'
import { VueDraggable } from 'vue-draggable-plus'
import { buildFormConfig } from '@/utils/custom'

interface FormConfig {
  items: FormItem[]
}

interface Props {
  visible: boolean
  size?: string
  title?: string
  items?: FormItem[]
  showImport?: boolean
  showPreview?: boolean
  showExport?: boolean
  showCancel?: boolean
  showSubmit?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  size: '90%',
  title: '表单生成器',
  items: () => [],
  showImport: false,
  showPreview: true,
  showExport: false,
  showCancel: true,
  showSubmit: true
})

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'submit', data: FormItem[]): void
}>()

// 生命周期钩子
onMounted(() => {
  // 初始化表单配置
  if (props.items) {
    formConfig.items = props.items
  }
})

// 预览表单数据
const previewFormData = reactive<Record<string, any>>({})

// 选中的表单项
const selectedItem = ref<FormItem | null>(null)

// 预览对话框可见性
const previewVisible = ref(false)

// 导入对话框可见性
const importVisible = ref(false)

// 导入配置字符串
const importConfigStr = ref('')

const previewData = computed(() => buildFormConfig(formConfig.items))

const fieldList = ref<any[]>([])

// 表单配置
const formConfig = reactive<FormConfig>({
  items: []
})

// 表单数据
const formData = computed(() => ({
  ...formConfig.items.reduce((acc, item) => {
    acc[item.key] = item.defaultValue || ''
    return acc
  }, {} as Record<string, any>)
}))

// 添加表单项
const addFormItem = (component: Record<'name' | 'type' | 'options', string>) => {
  const newItem: FormItem = {
    id: 0,
    key: `field_${Date.now()}`,
    name: component.name,
    label: component.name,
    type: component.type || 'input',
    width: '100%',
    disabled: false,
    tooltip: '',
    defaultValue: '',
    model: component.name,
    rules: [],
    showConfig: [],
    maxlength: 99,
    required: false,
    showWordLimit: false,
    inline: false,
    showLabel: true,
    options: [],
    min: 0,
    max: 99,
    allowHalf: false,
    showAlpha: false,
    multiple: false,
    placeholder: `请输入${component.name || 'default'}`,
    props: {},
    range: false,
    optionsType: 1,
    showType: 1,
    remote: '',
    dict: '',
    structureType: []
  }
  // 合并options
  if (component.options) {
    Object.assign(newItem, component.options)
  }
  selectFormItem(newItem)
  return newItem
}

// 选择表单项
const selectFormItem = (item: FormItem) => {
  selectedItem.value = item
  fieldList.value = formConfig.items
    .filter(item => item.key !== selectedItem.value?.key)
    .map(item => item.key)
}

// 删除表单项
const deleteFormItem = (key: string) => {
  const index = formConfig.items.findIndex(item => item.key === key)
  if (index > -1) {
    formConfig.items.splice(index, 1)
  }
  selectedItem.value = null
}

// 预览提交
const previewSubmit = (data: any) => {
  console.info('🚀 ~ previewSubmit ~ data:', data)
}

// 预览表单
const previewForm = () => {
  Object.assign(previewFormData, {})
  previewVisible.value = true
}

// 导出配置
const exportConfig = () => {
  const configStr = JSON.stringify(formConfig, null, 2)
  const blob = new Blob([configStr], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `form-config-${Date.now()}.json`
  a.click()
  URL.revokeObjectURL(url)
  ElMessage.success('配置导出成功')
}

// 导入配置
const importConfig = () => {
  importConfigStr.value = ''
  importVisible.value = true
}

// 执行导入配置
const doImportConfig = () => {
  try {
    const config = JSON.parse(importConfigStr.value) as FormConfig
    if (config.items) {
      formConfig.items = config.items
      selectedItem.value = null
      importVisible.value = false
      ElMessage.success('配置导入成功')
    } else {
      ElMessage.error('配置格式错误，缺少items字段')
    }
  } catch (e) {
    ElMessage.error('配置格式错误')
  }
}

const handleCancel = () => {
  emit('update:visible', false)
}

const handleSubmit = () => {
  const keyList = formConfig.items.map(item => item.key)
  if (new Set(keyList).size !== keyList.length) {
    ElMessage.error('字段标识不能重复')
    return
  }
  handleCancel()
  emit('submit', formConfig.items)
}
</script>

<style scoped lang="scss">
.form-builder-container {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background:
    radial-gradient(circle at top left, rgba(var(--el-color-primary-rgb), 0.08), transparent 320px),
    var(--art-gray-200);
}

.form-builder-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 20px;
  height: 64px;
  background-color: color-mix(in srgb, var(--default-box-color) 92%, transparent);
  border-bottom: 1px solid var(--art-gray-300);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);

  .form-builder-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .form-builder-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--art-gray-900);
    margin: 0;
  }

  .form-builder-actions {
    display: flex;
    gap: 10px;
  }
}

.form-builder-main {
  display: flex;
  flex: 1;
  overflow: hidden;

  .form-builder-sidebar {
    width: 308px;
    padding: 18px;
    background-color: var(--default-box-color);
    border-right: 1px solid var(--art-gray-300);
    overflow-y: auto;

    .component-group:not(:last-child) {
      margin-bottom: 8px;
    }

    .sidebar-title {
      position: relative;
      font-size: 14px;
      font-weight: 600;
      color: var(--art-gray-900);
      margin: 0 0 14px 0;
      padding-left: 10px;

      &::before {
        position: absolute;
        left: 0;
        top: 50%;
        width: 3px;
        height: 14px;
        content: '';
        background-color: var(--el-color-primary);
        border-radius: 6px;
        transform: translateY(-50%);
      }
    }

    .component-list {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 22px;

      .component-item {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 36px;
        padding: 5px;
        color: var(--art-gray-800);
        background-color: var(--art-gray-100);
        border: 1px solid var(--art-gray-300);
        border-radius: 10px;
        cursor: move;
        transition: all 0.2s ease;

        &:hover {
          color: var(--el-color-primary);
          background-color: rgba(var(--el-color-primary-rgb), 0.08);
          border-color: rgba(var(--el-color-primary-rgb), 0.45);
          box-shadow: 0 8px 18px rgba(0, 0, 0, 0.08);
          transform: translateY(-2px);
        }

        .component-icon {
          flex-shrink: 0;
          display: flex;
          align-items: center;
          justify-content: center;
          width: 24px;
          height: 24px;
          font-size: 16px;
          color: var(--el-color-primary);
          background-color: var(--default-box-color);
          border-radius: 7px;
        }

        .component-name {
          overflow: hidden;
          font-size: 12px;
          color: inherit;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    }
  }

  .form-builder-content {
    display: flex;
    flex: 1;
    overflow: hidden;

    .content-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      font-size: 16px;
      font-weight: 600;
      color: var(--art-gray-900);
      margin: 0 0 15px 0;
    }

    .title-left {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .form-preview {
      flex: 1;
      padding: 20px;
      overflow-y: auto;

      .preview-title {
        min-height: 32px;
      }

      .preview-actions {
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .preview-container {
        min-height: 500px;
        padding: 20px 0;
        background-color: var(--default-box-color);
        border: 1px solid var(--art-gray-300);
        border-radius: 12px;
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.06);

        .draggable-container {
          min-height: 500px;

          .form-item-container {
            position: relative;
            margin: 0 16px 10px;
            padding: 18px 20px;
            border: 1px solid transparent;
            border-radius: 10px;
            transition: all 0.2s ease;

            &:hover {
              background-color: var(--art-gray-100);
              border-color: var(--art-gray-300);
            }

            :deep(.el-form-item) {
              margin-bottom: 0 !important;
            }
          }

          .preview-label {
            display: flex;
            align-items: center;
          }

          .selected {
            background-color: rgba(var(--el-color-primary-rgb), 0.08);
            border-color: rgba(var(--el-color-primary-rgb), 0.3);
            border-left: 4px solid var(--el-color-primary);
            box-shadow: inset 0 0 0 1px rgba(var(--el-color-primary-rgb), 0.08);
          }

          .form-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            height: 500px;
            padding: 20px 0;
            color: var(--art-gray-600);
            text-align: center;
            border: 1px dashed var(--art-gray-400);
            border-radius: 12px;
            margin: 0 20px;
            background-color: var(--art-gray-100);
          }

          .form-empty-icon {
            font-size: 34px;
            color: var(--art-gray-500);
          }
        }
      }
    }

    .form-config {
      width: 400px;
      padding: 20px;
      background-color: var(--default-box-color);
      border-left: 1px solid var(--art-gray-300);
      overflow-y: auto;

      .delete-item-btn {
        font-size: 12px;
      }
    }

    .form-config-empty {
      display: flex;
      align-items: center;
      justify-content: center;
    }
  }
}

/* 拖拽相关样式 */
.sortable-ghost {
  opacity: 0.65;
  color: var(--el-color-primary);
  background: rgba(var(--el-color-primary-rgb), 0.1);
  border: 1px dashed var(--el-color-primary);
  border-radius: 10px;
}
</style>
