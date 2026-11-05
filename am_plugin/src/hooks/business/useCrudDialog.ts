import { ref, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'

interface CrudApiLike {
  save: (data: any) => Promise<any>
  update: (data: any) => Promise<any>
}

export interface CrudDialogOptions<T extends Record<string, any>> {
  /** 表单数据（reactive 对象） */
  form: T
  /** 组件 props（内含 visible/modelValue、type、editData 等） */
  props: Readonly<Record<string, any>>
  /** 组件 emit */
  emit: (...args: any[]) => void
  /** 保存/更新接口 */
  api: CrudApiLike
  /** 关闭事件名，默认 update:visible */
  closeEvent?: string
  /** 成功事件名，默认 submit */
  submitEvent?: string
  /** 成功后是否回传表单数据，默认 true */
  emitData?: boolean
  /** 成功后是否关闭弹窗，默认 true */
  closeOnSuccess?: boolean
  /** 取消时是否重置表单，默认 false */
  resetOnCancel?: boolean
  /** 校验失败时是否提示错误消息，默认 false（静默） */
  showValidationError?: boolean
  /** 是否跳过基础字段回填循环（自定义加载时用），默认 false */
  skipBaseLoad?: boolean
  /** 弹窗标题，默认按新增/编辑返回「新建/编辑」 */
  titleText?: (isEdit: boolean) => string
  /** 重置字段（不含 formRef.reset） */
  resetFields?: () => void
  /** 编辑态判定，默认 type === 'edit' */
  loadIsEdit?: (row: any, type: string) => boolean
  /** 弹窗打开（watch 触发）时、加载数据前的重置 */
  beforeWatchLoad?: () => void
  /** 基础字段回填后的业务处理 */
  afterLoad?: (row: any) => void | Promise<void>
  /** 关闭动画结束后的额外处理 */
  afterClosed?: () => void
  /** 校验通过后、提交接口前的处理（如二次表单校验、数据合并） */
  beforeSubmit?: () => void | Promise<void>
  /** 提交数据组装，默认返回 {...form} */
  buildPayload?: () => any
  /** 提交成功回调（emit 之前） */
  onSuccess?: (payload: any) => void
}

/**
 * 标准 CRUD 弹窗骨架
 * 收敛 formModel、标题、回填、提交、取消、关闭后的通用逻辑，
 * 业务差异通过钩子注入，弹窗文件只保留表单配置与业务处理。
 */
export function useCrudDialog<T extends Record<string, any>>(options: CrudDialogOptions<T>) {
  const { form, props, emit, api } = options
  const closeEvent = options.closeEvent ?? 'update:visible'
  const submitEvent = options.submitEvent ?? 'submit'
  const emitData = options.emitData ?? true
  const closeOnSuccess = options.closeOnSuccess ?? true
  const resetOnCancel = options.resetOnCancel ?? false
  const showValidationError = options.showValidationError ?? false
  const skipBaseLoad = options.skipBaseLoad ?? false

  const formRef = ref()
  const isEdit = ref(false)

  // reactive 常量不能直接作为 v-model（编译出的赋值会抛错），
  // 用可写计算属性做一层合并，保证组件回写时也是原地修改
  const formModel = computed({
    get: () => form,
    set: (val: any) => Object.assign(form, val)
  })

  const dialogTitle = computed(() => {
    const titleText = options.titleText ?? ((e: boolean) => (e ? '编辑' : '新建'))
    return titleText(isEdit.value)
  })

  const resetForm = (): void => {
    formRef.value?.reset()
    options.resetFields?.()
  }

  const loadFormData = async (): Promise<void> => {
    if (!props.editData || Object.keys(props.editData).length === 0) return
    const row = props.editData as any
    const loadIsEdit = options.loadIsEdit ?? ((_row: any, type: string) => type === 'edit')
    isEdit.value = loadIsEdit(row, props.type)
    if (!skipBaseLoad) {
      for (const key in form) {
        if (row[key] != null && row[key] != undefined) {
          ;(form as any)[key] = row[key]
        }
      }
    }
    await options.afterLoad?.(row)
  }

  const handleSubmit = async (): Promise<void> => {
    if (!formRef.value) return
    // 表单校验失败：按配置提示，不进入提交流程
    try {
      await formRef.value.validate()
    } catch {
      if (showValidationError) {
        ElMessage.error('表单校验失败，请检查输入')
      }
      return
    }
    // 接口失败：错误提示由 HTTP 拦截器统一处理
    try {
      await options.beforeSubmit?.()
      const payload = options.buildPayload ? options.buildPayload() : { ...form }
      if (isEdit.value) {
        await api.update(payload)
      } else {
        await api.save(payload)
      }
      options.onSuccess?.(payload)
      if (emitData) {
        emit(submitEvent, payload)
      } else {
        emit(submitEvent)
      }
      if (closeOnSuccess) {
        handleCancel()
      }
    } catch {
      // 提交失败，保持弹窗打开供重试
    }
  }

  const handleCancel = (): void => {
    if (resetOnCancel) {
      resetForm()
    }
    emit(closeEvent, false)
  }

  const handleClosed = (): void => {
    resetForm()
    isEdit.value = false
    options.afterClosed?.()
  }

  watch(
    () => [props.visible ?? props.modelValue, props.type, props.editData],
    ([visible]) => {
      if (visible) {
        options.beforeWatchLoad?.()
        loadFormData()
      }
    }
  )

  return {
    formRef,
    isEdit,
    formModel,
    dialogTitle,
    resetForm,
    loadFormData,
    handleSubmit,
    handleCancel,
    handleClosed
  }
}
