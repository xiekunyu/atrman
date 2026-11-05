<!-- WangEditor 富文本编辑器 插件地址：https://www.wangeditor.com/ -->
<template>
  <div class="editor-wrapper">
    <Toolbar
      class="editor-toolbar"
      :editor="editorRef"
      :mode="mode"
      :defaultConfig="toolbarConfig"
    />
    <Editor
      :style="{ height: height, overflowY: 'hidden' }"
      v-model="editorValue"
      :mode="mode"
      :defaultConfig="editorConfig"
      @onCreated="onCreateEditor"
    />
    <AmFileSelection
      v-model="imageSelectorVisible"
      title="选择图片"
      :multiple="true"
      :type="1"
      accept="image/*"
      @confirm="handleImageConfirm"
      @cancel="handleImageCancel"
    />
    <AmFileSelection
      v-model="videoSelectorVisible"
      title="选择视频"
      :multiple="false"
      :type="2"
      accept="video/*,.mp4,.webm,.ogg,.mov,.m4v"
      @confirm="handleVideoConfirm"
      @cancel="handleVideoCancel"
    />
  </div>
</template>

<script setup lang="ts">
  import '@wangeditor/editor/dist/css/style.css'
  import { onBeforeUnmount, onMounted, shallowRef, computed, ref } from 'vue'
  import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
  import { IDomEditor, IToolbarConfig, IEditorConfig } from '@wangeditor/editor'
  import AmFileSelection from '@/components/business/am-file-selection/index.vue'
  import { getFileUrl } from '@/utils/file'

  defineOptions({ name: 'AmWangEditor' })

  type RowRecord = Record<string, any>
  type ImageInsertFn = (url: string, alt: string, href: string) => void
  type VideoInsertFn = (url: string, poster?: string) => void

  // Props 定义
  interface Props {
    modelValue: string | undefined
    /** 编辑器高度 */
    height?: string
    /** 自定义工具栏配置 */
    toolbarKeys?: string[]
    /** 插入新工具到指定位置 */
    insertKeys?: { index: number; keys: string[] }
    /** 排除的工具栏项 */
    excludeKeys?: string[]
    /** 编辑器模式 */
    mode?: 'default' | 'simple'
    /** 占位符文本 */
    placeholder?: string
    /** 上传配置 */
    uploadConfig?: {
      maxFileSize?: number
      maxNumberOfFiles?: number
      server?: string
      // 是否开启自定义上传
      isCustomUpload?: boolean
    }
  }

  const props = withDefaults(defineProps<Props>(), {
    modelValue: '',
    height: '320px',
    mode: 'default',
    placeholder: '请输入内容...',
    excludeKeys: () => ['fontFamily'],
    isCustomUpload: false
  })

  const editorValue = defineModel<string | undefined>({ required: true })
  

  // 编辑器实例
  const editorRef = shallowRef<IDomEditor>()
  const imageSelectorVisible = ref(false)
  const videoSelectorVisible = ref(false)
  const pendingImageInsertFn = shallowRef<ImageInsertFn>()
  const pendingVideoInsertFn = shallowRef<VideoInsertFn>()

  // 常量配置
  const DEFAULT_UPLOAD_CONFIG = {
    maxFileSize: 3 * 1024 * 1024, // 3MB
    maxNumberOfFiles: 10,
    allowedFileTypes: ['image/*']
  } as const

  // 合并上传配置
  const mergedUploadConfig = computed(() => ({
    ...DEFAULT_UPLOAD_CONFIG,
    ...props.uploadConfig
  }))

  // 工具栏配置
  const toolbarConfig = computed((): Partial<IToolbarConfig> => {
    const config: Partial<IToolbarConfig> = {}

    // 完全自定义工具栏
    if (props.toolbarKeys && props.toolbarKeys.length > 0) {
      config.toolbarKeys = props.toolbarKeys
    }

    // 插入新工具
    if (props.insertKeys) {
      config.insertKeys = props.insertKeys
    }

    // 排除工具
    if (props.excludeKeys && props.excludeKeys.length > 0) {
      config.excludeKeys = props.excludeKeys
    }

    return config
  })

  // 编辑器配置
  const editorConfig: Partial<IEditorConfig> = {
    placeholder: props.placeholder,
    MENU_CONF: {
      uploadImage: {
        maxFileSize: mergedUploadConfig.value.maxFileSize,
        maxNumberOfFiles: mergedUploadConfig.value.maxNumberOfFiles,
        allowedFileTypes: mergedUploadConfig.value.allowedFileTypes,
        customBrowseAndUpload(insertFn: ImageInsertFn) {
          pendingImageInsertFn.value = insertFn
          imageSelectorVisible.value = true
        }
      },
      uploadVideo: {
        customBrowseAndUpload(insertFn: VideoInsertFn) {
          pendingVideoInsertFn.value = insertFn
          videoSelectorVisible.value = true
        }
      }
    }
  }

  const getPosterUrl = (file: RowRecord) => (file.thumb ? getFileUrl(file, true) : '')

  const getFileNameText = (file: RowRecord) =>
    String(file.name || file.path || file.url || '').toLowerCase()

  const hasFileUrl = (file: RowRecord) => Boolean(file.url && file.path)

  const isImageFile = (file: RowRecord) =>
    Number(file.file_type) === 1 || /\.(jpe?g|png|gif|webp|bmp|svg|ico)$/i.test(getFileNameText(file))

  const isVideoFile = (file: RowRecord) =>
    Number(file.file_type) === 2 || /\.(mp4|webm|ogg|mov|m4v)$/i.test(getFileNameText(file))

  const resetImageSelector = () => {
    imageSelectorVisible.value = false
    pendingImageInsertFn.value = undefined
  }

  const resetVideoSelector = () => {
    videoSelectorVisible.value = false
    pendingVideoInsertFn.value = undefined
  }

  const handleImageCancel = () => {
    resetImageSelector()
  }

  const handleVideoCancel = () => {
    resetVideoSelector()
  }

  const handleImageConfirm = (files: RowRecord[]) => {
    const insertFn = pendingImageInsertFn.value
    if (!insertFn) {
      resetImageSelector()
      return
    }

    if (!files?.length) {
      resetImageSelector()
      return
    }

    const validFiles = files.filter((file) => hasFileUrl(file) && isImageFile(file))
    if (!validFiles.length) {
      ElMessage.warning(files.some((file) => !hasFileUrl(file)) ? '文件地址无效' : '请选择图片文件')
      return
    }

    const maxNumberOfFiles = mergedUploadConfig.value.maxNumberOfFiles
    const insertFiles = validFiles.slice(0, maxNumberOfFiles)
    if (validFiles.length > maxNumberOfFiles) {
      ElMessage.warning(`最多只能选择 ${maxNumberOfFiles} 张图片`)
    }

    insertFiles.forEach((file) => {
      insertFn(getFileUrl(file, false), file.name || '', '')
    })
    ElMessage.success('图片插入成功')
    resetImageSelector()
  }

  const handleVideoConfirm = (files: RowRecord[]) => {
    const insertFn = pendingVideoInsertFn.value
    if (!insertFn) {
      resetVideoSelector()
      return
    }

    const file = files?.[0]
    if (!file) {
      resetVideoSelector()
      return
    }

    if (!hasFileUrl(file)) {
      ElMessage.warning('文件地址无效')
      return
    }

    if (!isVideoFile(file)) {
      ElMessage.warning('请选择视频文件')
      return
    }

    insertFn(getFileUrl(file, false), getPosterUrl(file))
    ElMessage.success('视频插入成功')
    resetVideoSelector()
  }

  // 编辑器创建回调
  const onCreateEditor = (editor: IDomEditor) => {
    editorRef.value = editor

    // 监听全屏事件
    editor.on('fullScreen', () => {})

    // 确保在编辑器创建后应用自定义图标
    applyCustomIcons()
  }

  // 应用自定义图标（带重试机制）
  const applyCustomIcons = () => {
    let retryCount = 0
    const maxRetries = 10
    const retryDelay = 100

    const tryApplyIcons = () => {
      const editor = editorRef.value
      if (!editor) {
        if (retryCount < maxRetries) {
          retryCount++
          setTimeout(tryApplyIcons, retryDelay)
        }
        return
      }

      // 获取当前编辑器的工具栏容器
      const editorContainer = editor.getEditableContainer().closest('.editor-wrapper')
      if (!editorContainer) {
        if (retryCount < maxRetries) {
          retryCount++
          setTimeout(tryApplyIcons, retryDelay)
        }
        return
      }

      const toolbar = editorContainer.querySelector('.w-e-toolbar')
      const toolbarButtons = editorContainer.querySelectorAll('.w-e-bar-item button[data-menu-key]')

      if (toolbar && toolbarButtons.length > 0) {
        return
      }

      // 如果工具栏还没渲染完成，继续重试
      if (retryCount < maxRetries) {
        retryCount++
        setTimeout(tryApplyIcons, retryDelay)
      } else {
        console.warn('工具栏渲染超时，无法应用自定义图标 - 编辑器实例:', editor.id)
      }
    }

    // 使用 requestAnimationFrame 确保在下一帧执行
    requestAnimationFrame(tryApplyIcons)
  }

  // 暴露编辑器实例和方法
  defineExpose({
    /** 获取编辑器实例 */
    getEditor: () => editorRef.value,
    /** 设置编辑器内容 */
    setHtml: (html: string) => editorRef.value?.setHtml(html),
    /** 获取编辑器内容 */
    getHtml: () => editorRef.value?.getHtml(),
    /** 清空编辑器 */
    clear: () => editorRef.value?.clear(),
    /** 聚焦编辑器 */
    focus: () => editorRef.value?.focus()
  })

  // 生命周期
  onMounted(() => {
    editorValue.value = props.modelValue
    // 图标替换已在 onCreateEditor 中处理
  })

  onBeforeUnmount(() => {
    resetImageSelector()
    resetVideoSelector()
    const editor = editorRef.value
    if (editor) {
      editor.destroy()
    }
  })
</script>

<style lang="scss">
  @use './style';
</style>
