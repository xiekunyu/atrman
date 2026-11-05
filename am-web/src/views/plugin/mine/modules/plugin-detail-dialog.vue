<template>
  <div class="detail-page">
    <div class="detail-page-header">
      <ElButton class="detail-back" @click="emit('back')">
        <ArtSvgIcon icon="ri:arrow-left-line" />
      </ElButton>
      <h2>应用详情</h2>
    </div>

    <div class="detail-card">
      <div class="detail-header">
        <div class="detail-app-icon" :class="{ 'is-image': isImageAppIcon }" :style="iconStyle">
          <img
            v-if="isImageAppIcon"
            :src="appIcon"
            class="detail-app-icon-img"
            alt=""
            @error="handleIconError"
          />
          <ArtSvgIcon v-else :icon="appIcon" />
        </div>
        <div class="detail-app-main">
          <div class="detail-title-row">
            <h2>{{ detail.title || detail.name || '应用应用' }}</h2>
            <ElButton
              v-if="isDevMode"
              link
              type="primary"
              class="detail-title-edit"
              @click="emit('edit')"
            >
              <ArtSvgIcon icon="ri:edit-line" />
            </ElButton>
            <div class="detail-actions">
              <ElButton v-if="appEntry" type="primary" plain @click="openAppEntry">
                <ArtSvgIcon icon="ri:external-link-line" />
                打开应用
              </ElButton>
              <ElButton
                v-if="isDevMode"
                type="warning"
                plain
                v-auth="'system:plugin:scaffold'"
                @click="emit('scaffold')"
              >
                <ArtSvgIcon icon="ri:code-box-line" />
                初始化应用
              </ElButton>
              <ElButton
                type="info"
                plain
                :loading="backuping"
                v-auth="'system:plugin:backup'"
                @click="handleBackup"
              >
                <ArtSvgIcon icon="ri:archive-line" />
                备份应用
              </ElButton>
              <ElButton
                v-if="hasComposerDeps"
                type="success"
                plain
                v-auth="'system:plugin:composer'"
                @click="emit('composer')"
              >
                <ArtSvgIcon icon="ri:stack-line" />
                后端依赖
              </ElButton>
              <ElButton
                v-if="isDevMode"
                type="primary"
                v-auth="'system:plugin:package'"
                @click="emit('package')"
              >
                <ArtSvgIcon icon="ri:file-zip-line" />
                生成安装包
              </ElButton>
            </div>
          </div>
          <p>{{ detail.description || detail.title || detail.name || '-' }}</p>
        </div>
      </div>

      <div class="detail-divider" />

      <div class="detail-block">
        <div class="detail-info-grid">
          <div class="detail-info-item">
            <span>应用标识</span>
            <div class="detail-info-value">
              <strong class="detail-mono">{{ detail.name || emptyText }}</strong>
              <ElButton v-if="detail.name" link type="primary" @click="copyText(detail.name)">
                复制
              </ElButton>
            </div>
          </div>
          <div class="detail-info-item">
            <span>版本</span>
            <strong class="detail-mono" :class="{ 'is-empty': !detail.version }">
              {{ detail.version || emptyText }}
            </strong>
          </div>

          <div class="detail-info-item">
            <span>作者</span>
            <strong :class="{ 'is-empty': !detail.author }">
              {{ detail.author || emptyText }}
            </strong>
          </div>
          <div class="detail-info-item">
            <span>基座版本</span>
            <strong class="detail-mono">
              {{ compatibilityInfo.current_version || detail.app_version || emptyText }}
            </strong>
          </div>
          <div class="detail-info-item">
            <span>最低支持</span>
            <strong
              class="detail-mono"
              :class="{ 'is-empty': !compatibilityInfo.required_version }"
            >
              {{ compatibilityInfo.required_version || '未声明' }}
            </strong>
          </div>
          <div class="detail-info-item">
            <span>安装时间</span>
            <strong :class="{ 'is-empty': !detail.installed_at }">
              {{ detail.installed_at || emptyTimeText }}
            </strong>
          </div>
          <div class="detail-info-item">
            <span>启用时间</span>
            <strong :class="{ 'is-empty': !detail.enabled_at }">
              {{ detail.enabled_at || emptyTimeText }}
            </strong>
          </div>
          <div class="detail-info-item">
            <span>兼容状态</span>
            <div class="detail-info-value">
              <ElTag :type="compatibilityInfo.compatible === false ? 'danger' : 'success'">
                {{ compatibilityInfo.compatible === false ? '不兼容' : '兼容' }}
              </ElTag>
            </div>
          </div>
          <div class="detail-info-item">
            <span>状态</span>
            <div class="detail-info-value">
              <ElSwitch
                :model-value="Number(detail.status) === 1"
                :active-value="true"
                :inactive-value="false"
                :loading="switchLoading"
                :disabled="!canToggleStatus"
                @change="handleStatusChange"
              />
            </div>
          </div>
        </div>
        <div class="mb-4">
          <el-alert
            class="mt-4"
            v-if="detail.error_message"
            title="异常信息"
            type="error"
            description="detail.error_message"
            show-icon
          />
        </div>
        <div class="detail-row detail-row-wide auth-row">
          <div class="detail-label">可见范围</div>
          <div class="detail-value auth-value">
            <AmStructureForm
              v-model="authForm"
              v-model:display-data="authDisplayData"
              :type="7"
              placeholder="选择可见人员、部门或标签"
            />
            <div class="auth-foot">
              <span class="auth-tip">未选择任何对象时，所有人可见</span>
              <ElButton
                v-if="isAuthChanged"
                type="primary"
                :loading="authSaving"
                v-auth="'system:plugin:auth'"
                @click="saveAuth"
              >
                保存可见范围
              </ElButton>
            </div>
          </div>
        </div>
      </div>

      <div class="detail-divider" />

      <ElTabs class="detail-tabs">
        <ElTabPane label="配置">
          <div class="plugin-config-tab-body">
            <EditConfig :app_name="detail.name" :mode="detail.is_dev ? 'edit' : 'view'" />
          </div>
        </ElTabPane>

        <ElTabPane label="依赖">
          <ElAlert type="info" show-icon :closable="false">
            依赖是应用之间的关系，有些应用需要依赖另一个应用，才能正常运行，比如内容管理可能需要依赖会员中心。
          </ElAlert>
          <div v-if="isDevMode" class="dependency-toolbar">
            <ElButton
              type="primary"
              v-auth="'system:plugin:dependency:save'"
              @click="openDependencyDialog()"
              >新增依赖</ElButton
            >
          </div>
          <ArtTable :data="dependencyRows" :columns="dependencyColumns" stripe>
            <template #satisfied="{ row }">
              <ElTag :type="row.satisfied === false ? 'danger' : 'success'" size="small">
                {{ row.satisfied === false ? '异常' : '正常' }}
              </ElTag>
            </template>
            <template #operation="{ row }">
              <ElButton
                link
                type="primary"
                v-auth="'system:plugin:dependency:update'"
                @click="openDependencyDialog(row)"
                >编辑</ElButton
              >
              <ElButton
                link
                type="danger"
                v-auth="'system:plugin:dependency:delete'"
                @click="handleDependencyDelete(row)"
                >删除</ElButton
              >
            </template>
          </ArtTable>

          <template v-if="hasComposerDeps">
            <div class="detail-divider composer-divider" />
            <div class="composer-dep-block">
              <div class="composer-dep-head">
                <span class="composer-dep-title">Composer 依赖</span>
                <ElTag :type="composerStatusTag.type" size="small" effect="dark">
                  {{ composerStatusTag.text }}
                </ElTag>
                <ElButton
                  link
                  type="primary"
                  v-auth="'system:plugin:composer'"
                  @click="emit('composer')"
                  >检测 / 安装依赖</ElButton
                >
              </div>
              <ElTable v-if="composerRows.length" :data="composerRows" size="small" border>
                <ElTableColumn prop="package" label="包名" min-width="180" />
                <ElTableColumn prop="constraint" label="需要约束" width="120" />
                <ElTableColumn prop="version" label="已装版本" width="120" />
                <ElTableColumn label="状态" width="110">
                  <template #default="{ row }">
                    <ElTag :type="row.tagType" size="small" effect="plain">{{ row.tagText }}</ElTag>
                  </template>
                </ElTableColumn>
              </ElTable>
              <div v-else class="composer-dep-empty"
                >该应用已声明依赖，点击右上角检测 / 安装查看详情。</div
              >
            </div>
          </template>

          <ElDialog
            v-model="dependencyDialogVisible"
            :title="editingDependency ? '编辑依赖' : '新增依赖'"
            width="520px"
            destroy-on-close
          >
            <AmForm
              ref="dependencyFormRef"
              v-model="dependencyForm"
              :items="dependencyFormItems"
              :rules="dependencyRules"
              :span="24"
              label-width="90px"
              :show-reset="false"
              :show-submit="false"
            />
            <div class="dependency-form-tip">版本号填写最低版本，如 1.0.0，不需要填写 &gt;=</div>
            <template #footer>
              <ElButton @click="dependencyDialogVisible = false">取消</ElButton>
              <ElButton type="primary" :loading="dependencySaving" @click="handleDependencySave"
                >保存</ElButton
              >
            </template>
          </ElDialog>
        </ElTabPane>

        <ElTabPane label="小部件">
          <ElAlert type="info" show-icon :closable="false">
            小部件是应用的一种特殊组件，都是采用远程URL加载的，可以是Vue组件、HTML页面等。用于在后台工作台展示的统计、图表等基本信息。
          </ElAlert>
          <div v-if="isDevMode" class="widget-toolbar">
            <ElButton type="primary" @click="openWidgetDialog()">新增小部件</ElButton>
          </div>
          <ArtTable :data="data" :loading="loading" :columns="columns" stripe>
            <template #cate="{ row }">
              <ElTag :type="row.cate === 1 ? 'primary' : 'info'" size="small">
                {{ row.cate === 1 ? 'Vue' : 'HTML' }}
              </ElTag>
            </template>
            <template #operation="{ row }">
              <ElButton link type="primary" @click="openWidgetDialog(row)">编辑</ElButton>
              <ElButton link type="danger" @click="handleWidgetDelete(row)">删除</ElButton>
            </template>
          </ArtTable>

          <ElDialog
            v-model="widgetDialogVisible"
            :title="editingWidget ? '编辑小部件' : '新增小部件'"
            width="520px"
            destroy-on-close
          >
            <AmForm
              ref="widgetFormRef"
              v-model="widgetForm"
              :items="widgetFormItems"
              :rules="widgetRules"
              :span="24"
              label-width="80px"
              :show-reset="false"
              :show-submit="false"
            />
            <div class="widget-form-tip">
              URL 由后端根据组件标识自动生成，保存后将在应用 public/widget 目录创建对应空文件
            </div>
            <template #footer>
              <ElButton @click="widgetDialogVisible = false">取消</ElButton>
              <ElButton type="primary" :loading="widgetSaving" @click="handleWidgetSave"
                >保存</ElButton
              >
            </template>
          </ElDialog>
        </ElTabPane>

        <ElTabPane label="Manifest">
          <div v-if="isDevMode" class="manifest-toolbar">
            <ElButton type="primary" :loading="manifestSaving" @click="saveManifest"
              >保存 Manifest</ElButton
            >
          </div>
          <ElInput
            v-if="isDevMode"
            v-model="manifestJsonText"
            type="textarea"
            :rows="18"
            class="manifest-editor"
          />
          <pre v-else class="json-box">{{ formatJson(detail.manifest || {}) }}</pre>
        </ElTabPane>
      </ElTabs>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
  import { useTable } from '@/hooks/core/useTable'
  import type { FormItem } from '@/components/business/am-form/index.vue'
  import pluginApi from '@/api/plugin/app'
  import {
    canEnablePlugin,
    getComposerState,
    getComposerStatus,
    getRequirementMessage,
    isImageIcon,
    parseManifest
  } from '@/utils/plugin'
  import AmForm from '@/components/business/am-form/index.vue'
  import AmStructureForm from '@/components/business/am-form/structure/index.vue'
  import EditConfig from '@/views/datam/config/modules/edit-config.vue'
  import { useDictStore } from '@/store/modules/dict'

  const dictStore = useDictStore()

  type RowRecord = Record<string, any>

  interface PluginAuthForm {
    user_ids: number[]
    dept_ids: number[]
    tags_ids: number[]
    dept_items: Array<{ id: number; is_main: number }>
  }

  interface Props {
    detail?: Record<string, any>
    switchLoading?: boolean
  }

  interface Emits {
    (e: 'back'): void
    (e: 'statusChange', value: boolean): void
    (e: 'edit'): void
    (e: 'saved'): void
    (e: 'scaffold'): void
    (e: 'package'): void
    (e: 'composer'): void
  }

  const props = withDefaults(defineProps<Props>(), {
    detail: () => ({}),
    switchLoading: false
  })

  const emit = defineEmits<Emits>()

  const detail = computed(() => props.detail || {})
  const emptyText = '未设置'
  const emptyTimeText = '未记录'
  const manifestSaving = ref(false)
  const manifestJsonText = ref('{}')
  const authSaving = ref(false)
  const authDisplayData = ref<RowRecord[]>([])
  const authForm = ref<PluginAuthForm>({
    user_ids: [],
    dept_ids: [],
    tags_ids: [],
    dept_items: []
  })

  const dependencySaving = ref(false)
  const dependencyDialogVisible = ref(false)
  const editingDependency = ref<RowRecord | null>(null)
  const dependencyFormRef = ref()
  const dependencyForm = ref<RowRecord>({
    dependency_name: '',
    dependency_title: '',
    dependency_type: 'plugin',
    dependency_version: ''
  })
  const dependencyRules = reactive<FormRules>({
    dependency_name: [
      { required: true, message: '请输入依赖应用标识', trigger: 'blur' },
      {
        pattern: /^am_[a-z][a-z0-9_]*$/,
        message: '应用标识必须以 am_ 开头，并且只能包含小写字母、数字和下划线',
        trigger: 'blur'
      }
    ],
    dependency_type: [{ required: true, message: '请选择依赖类型', trigger: 'change' }],
    dependency_version: [
      {
        pattern: /^$|^v?\d+(\.\d+){0,3}(-[0-9A-Za-z.-]+)?$/,
        message: '请输入版本号，如 1.0.0，不需要填写 >=',
        trigger: 'blur'
      }
    ]
  })
  const dependencyFormItems = computed<FormItem[]>(() => [
    {
      label: '标识',
      key: 'dependency_name',
      type: 'input',
      props: { placeholder: '如：am_user', maxlength: 80, disabled: !!editingDependency.value }
    },
    {
      label: '名称',
      key: 'dependency_title',
      type: 'input',
      props: { placeholder: '依赖应用名称', maxlength: 100 }
    },
    {
      label: '最低版本',
      key: 'dependency_version',
      type: 'input',
      props: { placeholder: '如：1.0.0', maxlength: 50 }
    }
  ])

  const widgetSaving = ref(false)
  const widgetDialogVisible = ref(false)
  const editingWidget = ref<RowRecord | null>(null)
  const widgetFormRef = ref()
  const widgetForm = ref<RowRecord>({
    name: '',
    title: '',
    description: '',
    cate: 1,
    type: 'widget',
    size: '',
    status: 1
  })
  const getDefaultWidgetSize = () =>
    String((dictStore.getByCode('widget_size') || [])[0]?.value ?? '')
  const widgetRules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入小部件标识', trigger: 'blur' },
      {
        pattern: /^[A-Za-z][A-Za-z0-9_]{0,31}$/,
        message: '只能输入字母、数字和下划线，并以字母开头，最多32个字符',
        trigger: 'blur'
      }
    ],
    title: [{ required: true, message: '请输入小部件名称', trigger: 'blur' }],
    cate: [{ required: true, message: '请选择组件分类', trigger: 'change' }],
    size: [{ required: true, message: '请选择组件尺寸', trigger: 'change' }]
  })
  const widgetFormItems = computed<FormItem[]>(() => [
    {
      label: '标识',
      key: 'name',
      type: 'input',
      props: { placeholder: '如：MyWidget', maxlength: 32, disabled: !!editingWidget.value }
    },
    {
      label: '名称',
      key: 'title',
      type: 'input',
      props: { placeholder: '组件显示名称', maxlength: 32 }
    },
    {
      label: '描述',
      key: 'description',
      type: 'input',
      props: {
        placeholder: '请输入部件描述',
        type: 'textarea',
        rows: 3,
        maxlength: 120,
        showWordLimit: true
      }
    },
    {
      label: '分类',
      key: 'cate',
      type: 'select',
      props: {
        disabled: !!editingWidget.value,
        options: [
          { value: 1, label: 'Vue' },
          { value: 2, label: 'HTML' }
        ]
      }
    },
    {
      label: '类型',
      key: 'type',
      type: 'select',
      props: {
        options: [
          { value: 'widget', label: 'widget' },
          { value: 'page', label: 'page' }
        ]
      }
    },
    {
      label: '尺寸',
      key: 'size',
      type: 'dict',
      props: { dict: 'widget_size', type: 'select', valueType: 'string' }
    },
    {
      label: '状态',
      key: 'status',
      type: 'dict',
      props: { dict: 'status', mode: 'border' }
    }
  ])

  const isDevMode = computed(() => Number(detail.value.is_dev || 0) === 1)

  const {
    columns,
    data,
    loading,
    refreshData: refreshWidgetList
  } = useTable({
    performance: {
      debounceTime: 500
    },
    core: {
      apiFn: pluginApi.widgetList,
      apiParams: {
        app_name: detail.value.name
      },
      columnsFactory: () => [
        { prop: 'name', label: '名称', minWidth: 120 },
        { prop: 'title', label: '标题', minWidth: 120 },
        { prop: 'description', label: '部件描述', minWidth: 180 },
        { prop: 'cate', label: '分类', minWidth: 80, useSlot: true },
        { prop: 'type', label: '类型', minWidth: 80 },
        { prop: 'size', label: '尺寸', minWidth: 90 },
        { prop: 'url', label: 'URL', minWidth: 220 },
        { prop: 'status', label: '状态', minWidth: 70, dict: 'status', amType: 'dict' },
        {
          prop: 'operation',
          label: '操作',
          width: 120,
          fixed: 'right',
          useSlot: true,
          hidden: !isDevMode.value
        }
      ]
    }
  })

  const parseJsonObject = (value: unknown): Record<string, any> => {
    if (!value) return {}
    if (typeof value === 'string') {
      try {
        const parsed = JSON.parse(value)
        return parsed && typeof parsed === 'object' ? parsed : {}
      } catch {
        return {}
      }
    }
    return typeof value === 'object' ? (value as Record<string, any>) : {}
  }

  const normalizeIds = (ids: unknown): number[] => {
    if (!Array.isArray(ids)) return []
    return [...new Set(ids.map((id) => Number(id)).filter((id) => id > 0))]
  }

  const normalizeAuth = (value: unknown): PluginAuthForm => {
    const auth = parseJsonObject(value)
    const deptIds = normalizeIds(auth.dept_ids)
    const deptItems = Array.isArray(auth.dept_items)
      ? auth.dept_items
          .map((item: RowRecord) => ({
            id: Number(item.id || 0),
            is_main: item.is_main ? 1 : 0
          }))
          .filter(
            (item: { id: number; is_main: number }) => item.id > 0 && deptIds.includes(item.id)
          )
      : []

    return {
      user_ids: normalizeIds(auth.user_ids),
      dept_ids: deptIds,
      tags_ids: normalizeIds(auth.tags_ids),
      dept_items: deptItems
    }
  }

  const normalizeDisplayData = (value: unknown): RowRecord[] => {
    if (!Array.isArray(value)) return []
    return value
      .map((item: RowRecord) => {
        const id = Number(item.id || 0)
        const stype = item.stype === 'tag' ? 'tags' : item.stype
        return {
          ...item,
          id,
          stype,
          key: item.key || `${stype}_${id}`,
          is_main: item.is_main ? 1 : 0
        }
      })
      .filter((item) => item.id > 0 && ['dept', 'user', 'tags'].includes(item.stype))
  }

  const resetAuthForm = () => {
    const auth = parseJsonObject(detail.value.auth)
    authForm.value = normalizeAuth(auth)
    authDisplayData.value = normalizeDisplayData(auth.displayData)
  }

  const getAuthPayload = (auth: PluginAuthForm) => ({
    user_ids: normalizeIds(auth.user_ids),
    dept_ids: normalizeIds(auth.dept_ids),
    tags_ids: normalizeIds(auth.tags_ids)
  })

  const stringifyAuthPayload = (auth: PluginAuthForm) =>
    JSON.stringify({
      user_ids: [...normalizeIds(auth.user_ids)].sort((a, b) => a - b),
      dept_ids: [...normalizeIds(auth.dept_ids)].sort((a, b) => a - b),
      tags_ids: [...normalizeIds(auth.tags_ids)].sort((a, b) => a - b)
    })

  const isAuthChanged = computed(
    () =>
      stringifyAuthPayload(authForm.value) !==
      stringifyAuthPayload(normalizeAuth(detail.value.auth))
  )

  const deepClone = <T,>(value: T): T => JSON.parse(JSON.stringify(value ?? null))

  const manifest = computed(() => parseManifest(detail.value.manifest))
  const iconLoadFailed = ref(false)

  const resetManifestText = () => {
    manifestJsonText.value = formatJson(manifest.value)
  }

  const compatibilityInfo = computed(() =>
    detail.value.compatibility_info && typeof detail.value.compatibility_info === 'object'
      ? detail.value.compatibility_info
      : {
          current_version: detail.value.app_version || '',
          required_version: manifest.value.support_version || '',
          compatible: true,
          message: manifest.value.support_version ? '兼容' : '不兼容'
        }
  )

  const appEntry = computed(() => detail.value.entry || manifest.value.entry || '')

  const dependencies = computed(() => toArrayRows(manifest.value.dependencies))
  const dependencyRows = computed(() => {
    const infoMap = new Map(
      toArrayRows(detail.value.dependency_info).map((item) => [item.name, item])
    )
    return dependencies.value.map((dependency) => ({
      type: 'plugin',
      ...dependency,
      ...(infoMap.get(dependency.name) || {})
    }))
  })
  const dependencyColumns = computed(() => [
    { prop: 'name', label: '标识', minWidth: 150 },
    { prop: 'title', label: '名称', minWidth: 140 },
    { prop: 'version', label: '要求版本', minWidth: 120 },
    { prop: 'current_version', label: '当前版本', minWidth: 120 },
    { prop: 'satisfied', label: '状态', minWidth: 90, useSlot: true },
    { prop: 'message', label: '说明', minWidth: 180 },
    {
      prop: 'operation',
      label: '操作',
      width: 120,
      fixed: 'right' as const,
      useSlot: true,
      hidden: !isDevMode.value
    }
  ])

  // ─── Composer 依赖展示（仅展示上次落库检测状态，实时检测/安装走弹窗） ───
  const composerStateInfo = computed(() => getComposerState(detail.value))

  const composerRequires = computed<Record<string, string>>(() => {
    const declared = detail.value.composer_requires
    const fromState = composerStateInfo.value.requires
    const base =
      declared && typeof declared === 'object'
        ? declared
        : fromState && typeof fromState === 'object'
          ? fromState
          : {}
    const out: Record<string, string> = {}
    Object.keys(base).forEach((pkg) => (out[pkg] = String(base[pkg] ?? '')))
    return out
  })

  const hasComposerDeps = computed(
    () =>
      Number(detail.value.has_composer_deps) === 1 || Object.keys(composerRequires.value).length > 0
  )

  const composerStatusTag = computed(() => {
    const map: Record<string, { text: string; type: 'success' | 'warning' | 'danger' | 'info' }> = {
      satisfied: { text: '依赖已满足', type: 'success' },
      partial: { text: '部分依赖待安装', type: 'warning' },
      missing: { text: '依赖缺失', type: 'danger' }
    }
    return map[getComposerStatus(detail.value)] || { text: '未检测', type: 'info' }
  })

  const composerRows = computed(() => {
    const st = composerStateInfo.value
    const installed = st.installed || {}
    const missing = st.missing || {}
    const incompatible = st.incompatible || {}
    return Object.keys(composerRequires.value).map((pkg) => {
      let tagText = '未检测'
      let tagType: 'success' | 'warning' | 'danger' | 'info' = 'info'
      if (missing[pkg] !== undefined) {
        tagText = '缺失'
        tagType = 'danger'
      } else if (incompatible[pkg] !== undefined) {
        tagText = '不兼容'
        tagType = 'warning'
      } else if (installed[pkg] !== undefined) {
        tagText = '已满足'
        tagType = 'success'
      }
      return {
        package: pkg,
        constraint: composerRequires.value[pkg] || '*',
        version: installed[pkg] || incompatible[pkg]?.version || '-',
        tagText,
        tagType
      }
    })
  })

  // ─── 备份（复用现有插件备份接口） ───
  const backuping = ref(false)
  const handleBackup = async () => {
    if (backuping.value) return
    // 提前置位，覆盖确认框阶段，避免重复点击再次弹出确认框
    backuping.value = true
    try {
      try {
        await ElMessageBox.confirm(
          `确定要备份「${detail.value.title || detail.value.name}」吗？`,
          '备份插件',
          { type: 'warning' }
        )
      } catch {
        return
      }
      await pluginApi.backup({ id: detail.value.id, name: detail.value.name })
      ElMessage.success('备份生成成功')
    } catch {
      // 失败提示由 http 拦截器统一弹出，这里吞掉避免未处理的 Promise 拒绝
    } finally {
      backuping.value = false
    }
  }

  // 打开应用前台：与会员首页一致，先向基座签一张绑定本应用的一次性票据，
  // 用带 ?ticket= 的地址新标签打开（前台本地优先复用会话，读不到再用票兑换）；签票失败退回直开原始 entry。
  const openAppEntry = async () => {
    const entry = appEntry.value
    if (!entry) return
    // 点击同步阶段先开空白占位窗口，避开弹窗拦截（await 后再 open 会被浏览器静默拦截）
    const win = window.open('', '_blank')
    let target = entry
    try {
      const res = await pluginApi.issueTicket({ app_id: detail.value.name, entry: 'entry' })
      target = res?.url || entry
    } catch {
      // 签票失败退回原始 entry，复用同一占位窗口导航
    }
    if (win) {
      win.opener = null // 手动断开引用，保持与 noopener 同等效果
      win.location.href = target
    } else {
      // 占位窗口被拦截时的兜底
      window.open(target, '_blank', 'noopener,noreferrer')
    }
  }

  const appIcon = computed(() =>
    iconLoadFailed.value
      ? 'ri:apps-2-line'
      : detail.value.icon || manifest.value.icon || 'ri:apps-2-line'
  )

  const isImageAppIcon = computed(() => isImageIcon(appIcon.value))

  const handleIconError = () => {
    iconLoadFailed.value = true
  }

  const iconStyle = computed(() => ({
    background: isImageAppIcon.value
      ? 'var(--el-fill-color-light)'
      : manifest.value.color || 'linear-gradient(135deg, #1f6bff, #0f8bff)'
  }))

  const canToggleStatus = computed(() => [0, 1].includes(Number(detail.value.status)))

  const formatJson = (value: any) => JSON.stringify(value, null, 2)

  const toArrayRows = (value: unknown): RowRecord[] =>
    Array.isArray(value) ? deepClone(value) : []

  const saveAuth = async () => {
    if (!detail.value.id) return

    authSaving.value = true
    try {
      await pluginApi.saveAuth({
        id: detail.value.id,
        auth: getAuthPayload(authForm.value)
      })
      emit('saved')
    } finally {
      authSaving.value = false
    }
  }

  const saveManifest = async () => {
    let manifestData: Record<string, any>
    try {
      manifestData = JSON.parse(manifestJsonText.value || '{}')
    } catch {
      ElMessage.error('Manifest JSON 格式错误')
      return
    }

    manifestData.name = detail.value.name
    manifestData.entry = manifestData.entry || detail.value.entry || ''
    manifestData.manage_entry =
      manifestData.manage_entry || detail.value.manage_entry || manifestData.entry
    manifestData.open_mode = manifestData.open_mode || detail.value.open_mode || 'micro'
    manifestData.is_dev = Number(detail.value.is_dev ?? manifestData.is_dev ?? 1)
    manifestSaving.value = true
    try {
      await pluginApi.update({
        id: detail.value.id,
        name: detail.value.name,
        title: manifestData.title || detail.value.title,
        version: manifestData.version || detail.value.version,
        author: manifestData.author ?? detail.value.author ?? '',
        description: manifestData.description ?? detail.value.description ?? '',
        entry: manifestData.entry,
        manage_entry: manifestData.manage_entry,
        is_dev: manifestData.is_dev,
        open_mode: manifestData.open_mode,
        dependencies: manifestData.dependencies ?? [],
        manifest: manifestData
      })
      emit('saved')
    } finally {
      manifestSaving.value = false
    }
  }

  const copyText = async (text?: string) => {
    if (!text) return
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  }

  const openDependencyDialog = (row?: RowRecord) => {
    if (row) {
      editingDependency.value = row
      dependencyForm.value = {
        dependency_name: row.name || '',
        dependency_title: row.title || '',
        dependency_version: row.version || ''
      }
    } else {
      editingDependency.value = null
      dependencyForm.value = {
        dependency_name: '',
        dependency_title: '',
        dependency_version: ''
      }
    }
    dependencyDialogVisible.value = true
  }

  const handleDependencySave = async () => {
    if (!dependencyFormRef.value) return
    try {
      await dependencyFormRef.value.validate()
    } catch {
      return
    }
    const form = dependencyForm.value
    const payload: Record<string, any> = {
      plugin_id: detail.value.id,
      app_name: detail.value.name,
      dependency_name: String(form.dependency_name || '').trim(),
      dependency_title: String(form.dependency_title || '').trim(),
      dependency_version: String(form.dependency_version || '').trim()
    }
    if (payload.dependency_name === detail.value.name) {
      ElMessage.warning('不能依赖当前应用自身')
      return
    }

    dependencySaving.value = true
    try {
      if (editingDependency.value) {
        await pluginApi.dependencyUpdate({
          ...payload,
          original_name: editingDependency.value.name
        })
      } else {
        await pluginApi.dependencySave(payload)
      }
      dependencyDialogVisible.value = false
      emit('saved')
    } finally {
      dependencySaving.value = false
    }
  }

  const handleDependencyDelete = async (row: RowRecord) => {
    try {
      await ElMessageBox.confirm(`确定删除依赖「${row.title || row.name}」？`, '删除确认', {
        type: 'warning'
      })
    } catch {
      return
    }
    try {
      await pluginApi.dependencyDelete({
        plugin_id: detail.value.id,
        app_name: detail.value.name,
        dependency_name: row.name
      })
      emit('saved')
    } catch {
      // error handled by http util
    }
  }

  const openWidgetDialog = (row?: RowRecord) => {
    if (row) {
      editingWidget.value = row
      widgetForm.value = { ...row }
    } else {
      editingWidget.value = null
      widgetForm.value = {
        name: '',
        title: '',
        description: '',
        cate: 1,
        type: 'widget',
        size: getDefaultWidgetSize(),
        status: 1
      }
    }
    widgetDialogVisible.value = true
  }

  const handleWidgetSave = async () => {
    if (!widgetFormRef.value) return
    try {
      await widgetFormRef.value.validate()
    } catch {
      return
    }
    const form = widgetForm.value
    widgetSaving.value = true
    try {
      const payload: Record<string, any> = { ...form, app_name: detail.value.name }
      if (editingWidget.value) {
        payload.id = editingWidget.value.id
        await pluginApi.widgetUpdate(payload)
      } else {
        await pluginApi.widgetSave(payload)
      }
      widgetDialogVisible.value = false
      await refreshWidgetList()
      emit('saved')
    } finally {
      widgetSaving.value = false
    }
  }

  const handleWidgetDelete = async (row: RowRecord) => {
    try {
      await ElMessageBox.confirm(`确定删除小部件「${row.title || row.name}」？`, '删除确认', {
        type: 'warning'
      })
    } catch {
      return
    }
    try {
      await pluginApi.widgetDelete({ id: row.id })
      await refreshWidgetList()
      emit('saved')
    } catch {
      // error handled by http util
    }
  }

  const handleStatusChange = (value: string | number | boolean) => {
    const status = Number(detail.value.status)
    const enabled = Boolean(value)
    if (enabled && status === 0 && !canEnablePlugin(detail.value)) {
      ElMessage.warning(getRequirementMessage(detail.value))
      return
    }
    if (!canToggleStatus.value) {
      ElMessage.warning(status === 2 ? '请先安装插件' : '插件状态异常，请先重载或重新安装')
      return
    }
    emit('statusChange', enabled)
  }

  watch(
    () => detail.value.id,
    () => {
      iconLoadFailed.value = false
      resetAuthForm()
      refreshWidgetList()
    },
    { immediate: true }
  )

  // 保存/切状态/编辑等流程会用 read() 结果替换 detailData（id 不变，上面的 watch 不触发），
  // 额外跟踪 auth 变化，保证可见范围回显跟随服务端数据
  watch(
    () => detail.value.auth,
    () => {
      resetAuthForm()
    }
  )

  watch(
    () => manifest.value,
    () => {
      resetManifestText()
    },
    { immediate: true, deep: true }
  )
</script>

<style scoped lang="scss">
  .detail-page-header {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 50px;
    margin: 0 -20px;
    border-bottom: 1px solid var(--el-border-color-lighter);

    h2 {
      margin: 0;
      font-size: 15px;
      font-weight: 600;
      color: var(--art-gray-900);
    }
  }

  .detail-back {
    position: absolute;
    left: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    padding: 0;
    color: var(--art-gray-900);
    background: var(--default-box-color);
    border: 1px solid var(--default-border);
    border-radius: 7px;

    &:hover {
      color: var(--el-color-primary);
      background: var(--art-active-color);
      border-color: var(--el-color-primary-light-7);
    }
  }

  .detail-card {
    padding: 20px;
    margin-top: 18px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
  }

  .detail-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 8px 0 28px;
  }

  .detail-app-icon {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    font-size: 42px;
    color: #fff;
    border-radius: 14px;

    &.is-image {
      overflow: hidden;
      color: inherit;
    }
  }

  .detail-app-icon-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  .detail-app-main {
    flex: 1;
    min-width: 0;
  }

  .detail-title-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;

    h2 {
      margin: 0;
      font-size: 24px;
      font-weight: 700;
      color: var(--art-text-gray-900);
    }
  }

  .detail-title-edit {
    font-size: 16px;
  }

  .detail-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
    margin-left: auto;

    // ElButton 会把默认插槽包进一层 span，图标与文字在同一 span 内，需用 flex 撑开间距
    :deep(.el-button > span) {
      display: inline-flex;
      gap: 5px;
      align-items: center;
    }
  }

  .detail-app-main p {
    margin: 8px 0 0;
    font-size: 14px;
    color: var(--el-text-color-secondary);
  }

  .detail-divider {
    height: 1px;
    background: var(--el-border-color-lighter);
  }

  .detail-block {
    padding: 20px 0 10px 0;
  }

  .detail-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
    margin-bottom: 10px;
  }

  .detail-info-item {
    min-width: 0;
    padding: 12px 14px;
    background: var(--el-fill-color-lighter);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;

    // 只命中直接子节点：ElTag 根节点也是 span，宽泛选择器会覆盖它的 inline-flex 使文字不居中
    > span {
      display: block;
      font-size: 12px;
      color: var(--art-gray-600);
    }

    > strong {
      display: block;
      max-width: 100%;
      margin-top: 8px;
      overflow: hidden;
      font-size: 14px;
      font-weight: 600;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    &--block {
      grid-column: 1 / -1;
    }
  }

  .detail-info-value {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 8px;
    font-size: 14px;
    font-weight: 600;

    strong {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .detail-info-text {
    margin: 8px 0 0;
    font-size: 13px;
    line-height: 1.6;
    word-break: break-all;
  }

  .detail-row {
    display: grid;
    grid-template-columns: 76px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    min-height: 38px;
    font-size: 14px;
  }

  .detail-row-wide {
    grid-column: 1 / -1;
  }

  .detail-label {
    color: var(--el-text-color-secondary);
  }

  .detail-value {
    display: flex;
    gap: 8px;
    min-width: 0;
    color: var(--art-text-gray-900);
    word-break: break-all;
  }

  // 标识、版本号等等宽对齐，便于跨卡片扫读
  .detail-mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }

  .danger {
    color: var(--el-color-danger);
  }

  // 空值用占位文案 + 弱化色，避免和真实取值混淆
  .is-empty {
    color: var(--el-text-color-placeholder);
  }

  .auth-row {
    align-items: center;
  }

  .auth-value {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .auth-foot {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
  }

  .auth-tip {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .link-text {
    color: var(--el-color-primary);
  }

  .compatibility-message {
    color: var(--el-text-color-secondary);
  }

  .detail-tabs {
    margin-top: 8px;
  }

  .plugin-config-tab-body {
    height: 560px;
  }

  .plugin-config-layout {
    min-height: 480px;
    overflow: hidden;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
  }

  .plugin-config-aside {
    display: flex;
    flex-direction: column;
    background: var(--el-fill-color-lighter);
    border-right: 1px solid var(--el-border-color-lighter);
  }

  .plugin-config-aside-header,
  .plugin-config-main-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 56px;
    padding: 0 16px;
    background: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .plugin-config-category-body {
    flex: 1;
    min-height: 0;
    padding: 12px;
    overflow: auto;
  }

  .plugin-config-category-table {
    width: 100%;
    overflow: hidden;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;

    :deep(.el-table__row) {
      cursor: pointer;
    }

    :deep(.current-row > td.el-table__cell) {
      background: var(--el-color-primary-light-9) !important;
    }
  }

  .plugin-config-category-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }

  .plugin-config-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    font-size: 16px;
  }

  .plugin-config-main {
    display: flex;
    flex-direction: column;
    padding: 0;
    background: var(--el-bg-color);
  }

  .plugin-config-title {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;

    span {
      font-size: 12px;
      font-weight: 400;
      color: var(--el-text-color-secondary);
    }
  }

  .plugin-config-toolbar {
    display: flex;
    flex-shrink: 0;
    justify-content: flex-end;
    gap: 8px;
  }

  .plugin-config-form-body {
    flex: 1;
    min-height: 0;
    padding: 24px 28px;
    overflow: auto;
  }

  .manifest-toolbar,
  .dependency-toolbar,
  .widget-toolbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    margin-top: 12px;
  }

  .dependency-form-tip,
  .widget-form-tip {
    padding: 8px 12px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
    background: var(--el-fill-color-lighter);
    border-radius: 6px;
  }

  .composer-divider {
    margin: 18px 0 4px;
  }

  .composer-dep-block {
    padding-top: 16px;
  }

  .composer-dep-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
  }

  .composer-dep-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--art-text-gray-900);
  }

  .composer-dep-empty {
    padding: 12px 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .json-box {
    max-height: 500px;
    padding: 12px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-all;
    background: var(--el-fill-color-light);
    border-radius: 6px;
  }

  .manifest-editor {
    font-family: var(--el-font-family);
  }

  @media (max-width: 768px) {
    .detail-info-grid {
      grid-template-columns: 1fr;
    }

    .plugin-config-layout {
      flex-direction: column;
    }

    .plugin-config-aside {
      width: 100% !important;
      border-right: 0;
      border-bottom: 1px solid var(--el-border-color-lighter);
    }

    .plugin-config-main-header {
      align-items: flex-start;
      flex-direction: column;
      gap: 12px;
      padding: 14px 16px;
    }

    .plugin-config-toolbar {
      width: 100%;
      justify-content: flex-start;
    }

    .plugin-config-form-body {
      padding: 18px 16px;
    }
    .plugin-config-tab-body {
      height: auto;
    }
  }
</style>
