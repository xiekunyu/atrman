<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="860px"
    align-center
    class="menu-dialog"
    @closed="handleClosed"
  >
    <AmForm
      ref="formRef"
      v-model="formModel"
      :items="formItems"
      :rules="rules"
      :span="width > 640 ? 12 : 24"
      :gutter="20"
      label-width="100px"
      :show-reset="false"
      :show-submit="false"
    >
      <template #iconPicker>
        <IconPicker v-if="!isPluginEntry" v-model="form.icon" />
        <span v-else class="plugin-entry-icon-tip">
          插件图标由插件自身管理，请前往插件编辑页更换图标
        </span>
      </template>
      <template #parentId>
        <el-tree-select
          v-model="form.pid"
          :data="menuTree"
          :render-after-expand="false"
          check-strictly
          clearable
        />
      </template>
    </AmForm>

    <template #footer>
      <span class="dialog-footer">
        <ElButton @click="handleCancel">取 消</ElButton>
        <ElButton type="primary" @click="handleSubmit">确 定</ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { reactive, computed } from 'vue'
  import type { FormRules } from 'element-plus'
  import menuApi from '@/api/system/menu'
  import type { AppRouteRecord } from '@/types/router'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import { useWindowSize } from '@vueuse/core'
  import { createLabelTooltip } from '@/utils/custom'
  import IconPicker from '@/components/business/am-form/icon/index.vue'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  const { width } = useWindowSize()

  interface MenuFormData {
    id: number
    name: string
    code: string
    app_name: string
    type: number
    path: string
    method: string
    component: string
    icon: string
    sort: number
    pid: number
    status: number
    is_iframe: number
    is_hide: number
    is_cache: number
    is_fixed: number
    is_full_page: number
  }

  interface Props {
    visible: boolean
    editData?: AppRouteRecord | any
    type: string
    lockType?: boolean
    pid?: number
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit', data: MenuFormData): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    type: 'add',
    lockType: false,
    pid: 0
  })

  const emit = defineEmits<Emits>()

  const menuTree = ref<any[]>([])

  const form = reactive<MenuFormData>({
    id: 0,
    name: '',
    code: '',
    app_name: '',
    type: 1,
    path: '',
    method: '',
    component: '',
    icon: '',
    sort: 50,
    pid: 0,
    status: 1,
    is_iframe: 0,
    is_hide: 0,
    is_cache: 0,
    is_fixed: 0,
    is_full_page: 0
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: menuApi,
      showValidationError: true,
      resetFields: () => {
        form.type = 1
        form.status = 1
        form.sort = 50
        form.pid = 0
      },
      beforeWatchLoad: () => {
        form.id = 0
        form.pid = props.pid
      },
      buildPayload: () => {
        // 插件标识为空时强制传空字符串，代表系统基座菜单（避免字段被清除后不更新）
        return { ...form, app_name: form.app_name || '' }
      }
    })

  // 插件“管理入口”菜单（保留 code app:entry:%）：图标由插件 manifest 维护，编辑时锁定不可修改
  const isPluginEntry = computed(() => String(form.code || '').startsWith('app:entry:'))

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入菜单名称', trigger: 'blur' },
      { min: 2, max: 20, message: '长度在 2 到 20 个字符', trigger: 'blur' }
    ],
    path: [{ required: true, message: '请输入路由地址', trigger: 'blur' }],
    code: [{ required: true, message: '输入菜单标识', trigger: 'blur' }],
    component: [{ required: true, message: '请输入组件路径', trigger: 'blur' }]
  })

  const formItems = computed<FormItem[]>(() => {
    const baseItems: FormItem[] = [
      {
        label: '菜单类型',
        key: 'type',
        span: 24,
        type: 'dict',
        props: { dict: 'menu_type', mode: 'button', type: 'radio' }
      }
    ]

    // Switch 组件的 span：小屏幕 12，大屏幕 6
    const switchSpan = width.value < 640 ? 12 : 6
    const switchProps = { activeValue: 1, inactiveValue: 0 }

    return [
      ...baseItems,
      {
        label: '上级菜单',
        key: 'pid',
        type: 'remote',
        props: { checkStrictly: true, clearable: true, remote: 'auth_menu', type: 'tree' }
      },
      { label: '菜单名称', key: 'name', type: 'input', props: { placeholder: '菜单名称' } },
      {
        label: createLabelTooltip('菜单标识', '如：User，如果为应用菜单，填写以am_开头的应用标识'),
        key: 'code',
        type: 'input',
        props: { placeholder: '如：User，am_user' },
        hidden: form.type === 3 || form.type === 4
      },

      {
        label: createLabelTooltip(
          '路由地址',
          '一级菜单：以 / 开头的绝对路径（如 /dashboard）\n二级及以下：相对路径（如 console、user）,外链就填写url'
        ),
        key: 'path',
        type: 'input',
        hidden: form.type === 3,
        props: { placeholder: '如：/dashboard 或 console' }
      },
      {
        label: createLabelTooltip(
          '组件路径',
          '一级父级菜单：填写 /index/index\n具体页面：填写组件路径（如 /system/user）\n目录菜单：留空'
        ),
        key: 'component',
        type: 'input',
        hidden: form.type === 3 || form.type === 4,
        props: { placeholder: '如：/system/user 或留空' }
      },
      { label: '图标', key: 'iconPicker', hidden: form.type === 3 || form.type === 4 },
      {
        label: createLabelTooltip('权限标识', '权限标识（按钮用），需要对应后端路由'),
        key: 'slug',
        type: 'input',
        hidden: form.type === 4,
        props: { placeholder: '如：user:add' }
      },
      {
        label: createLabelTooltip(
          '所属插件',
          '留空表示系统基座菜单；选择开发应用或自建应用，表示该菜单归属该插件，随插件启用/卸载管理'
        ),
        key: 'app_name',
        type: 'remote',
        props: {
          remote: 'plugin_list',
          type: 'select',
          clearable: true,
          placeholder: '留空为系统基座菜单'
        }
      },
      {
        label: '排序',
        key: 'sort',
        type: 'number',
        props: { min: 1, controlsPosition: 'right', style: { width: '100%' } }
      },
      {
        label: createLabelTooltip('是否启用', '菜单状态'),
        key: 'status',
        type: 'switch',
        span: switchSpan,
        props: switchProps
      },
      {
        label: createLabelTooltip('是否隐藏', '不显示在菜单中，可通过路由访问'),
        key: 'is_hide',
        type: 'switch',
        span: switchSpan,
        props: switchProps
      },
      {
        label: createLabelTooltip('是否内嵌', '是否内嵌iframe，外链时有效'),
        key: 'is_iframe',
        type: 'switch',
        span: switchSpan,
        props: switchProps
      },
      { label: '是否缓存', key: 'is_cache', type: 'switch', span: switchSpan, props: switchProps },
      {
        label: createLabelTooltip('是否固定', '是否固定在标签页中'),
        key: 'is_fixed',
        type: 'switch',
        span: switchSpan,
        props: switchProps
      },
      {
        label: '是否全屏',
        key: 'is_full_page',
        type: 'switch',
        span: switchSpan,
        props: switchProps
      }
    ]
  })
</script>

<style scoped>
  .plugin-entry-icon-tip {
    font-size: 12px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }
</style>
