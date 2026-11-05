<template>
    <ElDrawer
        :model-value="visible"
        :title="title"
        @update:model-value="handleCancel"
        :size="500"
      >

      <div class="preview-container">
        <el-image :src="getFileUrl(fileInfo, false)" class="preview-item image-item"  v-if="fileInfo.file_type === 1" fit="contain"/>
        <div class="preview-item file-item"  v-else> 
          <ArtSvgIcon icon="ri:image-2-line" class="icon text-3xl"/>
          <div>此文件不支持预览</div>
         </div>
      </div>

      <div class="grid grid-cols-2 gap-3 mt-4">
        <AmStatCard :value="fileData.storage?.name || '未知'" label="储存库"/>
        <AmStatCard :value="fileData?.parent?.name || '根目录'" label="所属目录"/>
        <AmStatCard :value="formatFileSize(fileInfo.size)" label="文件大小"/>
        <AmStatCard :value="fileData?.user?.nickname || '未知'" label="上传者"/>
      </div>

      <div class="file-extends mt-4">
        <h4>扩展信息</h4>
        <div class="word-break">
          <span class="font-bold">文件名称：</span>
          <div @click="handleEditName">{{ fileInfo.name }} 
            <ArtSvgIcon icon="ri:edit-line" class="edit-icon text-xl"/>
          </div>
        </div>
        <div v-if="fileInfo.file_type === 1 && fileInfo.extends"><span class="font-bold">图片尺寸：</span>{{fileInfo.extends.width}}px * {{fileInfo.extends.height}}px</div>
        <div class="flex items-center"><span class="font-bold">文件类型：</span>
          <AmDict
              dict="file_type"
              :value="fileInfo.file_type"
              render="tag"
            />
        </div>
        <div ><span class="font-bold">长传时间：</span>{{ fileInfo.create_time }}</div>
        <div ><span class="font-bold">更新时间：</span>{{ fileInfo.update_time }}</div>
      </div>

      <div class="file-link mt-4" v-if="links.length > 0">
        <div v-for="item in links" :key="item.name">
          <div class="font-bold mt-4"><b>{{item.name}}</b></div>
          <div class="link-item mt-1">
            <code>{{item.link}}</code>
            <div class="copy-btn" @click="copyToClipboard(item.link)">
              <ArtSvgIcon icon="ri:file-copy-2-line" class="icon text-xl"/>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button type="warning" @click="handleDownload">下载</el-button>
        <el-button type="primary" @click="handleCancel">关闭</el-button>
      </template>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { formatFileSize, downloadFile, getFileUrl } from '@/utils/file'
  import fileApi from '@/api/system/file'
  import { ElMessage, ElMessageBox } from 'element-plus'
  
  interface UserFormData {
    id: number
    name: string
    pid: number
    status: number
  }

  interface Props {
    visible: boolean
    fileInfo:any
    title?:string
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit', data: UserFormData): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    fileInfo: {},
    title: '文件详情'
  })

  const emit = defineEmits<Emits>()
  const fileData = ref<any>({})
  const links = ref<any>([])

  const loadFileData = async () => {
    fileData.value = await fileApi.read(props.fileInfo.id);
    const fileUrl = getFileUrl(props.fileInfo, false)
    const isImage = props.fileInfo.file_type === 1
    links.value = [
      {
        name: 'URL',
        link: fileUrl
      },
      ...(isImage ? [{
        name: 'HTML',
        link: `<img src="${fileUrl}" alt="${props.fileInfo?.name}" />`
      }] : []),
      {
        name: 'Markdown',
        link: isImage
          ? `![${props.fileInfo?.name}](${fileUrl})`
          : `[${props.fileInfo?.name}](${fileUrl})`
      }
    ]
  }
  
  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text)
    ElMessage.success('复制成功')
  }

  /**
   * 编辑文件名称
   */
  const handleEditName = () => {
    ElMessageBox.prompt('请输入新的文件名称,请保留文件扩展名', '编辑文件名称', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      inputPattern: /^[^\s]+$/,
      inputErrorMessage: '请输入新的文件名称',
      inputValue: props.fileInfo?.name
    }).then(({ value }) => {
      props.fileInfo.name = value
      fileApi.update({id: props.fileInfo.id, name: value})
    })
  }

  /**
   * 下载文件
   */
  const handleDownload = async (): Promise<void> => {
    const success = await downloadFile(getFileUrl(props.fileInfo, false), props.fileInfo?.name)
    if (success) {
      ElMessage.success('下载成功')
    } else {
      ElMessage.error('下载失败，请稍后重试')
    }
  }

  /**
   * 取消操作
   */
  const handleCancel = (): void => {
    emit('update:visible', false)
  }

  watch(() => props.fileInfo, loadFileData) 
</script>
<style scoped lang="scss">
.preview-container {
  display: flex;
  justify-content: center;
  align-items: center;
  .preview-item {
    width: 100%;
    height: 240px;
    border: solid 1px var(--art-gray-300);
    border-radius: 4px;
  }
  .file-item {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    font-size: 14px;
    color: var(--art-gray-500);
  }
}
.file-extends {
  background-color: var(--art-gray-100);
  border: solid 1px var(--art-gray-300);
  padding: 10px;
  border-radius: 4px;
  line-height: 2;
  h4 {
    color: var(--art-gray-500);
  }
}

.file-link {

  .link-item {
    word-break: break-word;
    background-color: var(--art-gray-100);
    padding: 10px 30px 10px 10px;
    border-radius: 4px;
    position: relative;
    &:hover {
      .copy-btn {
        display: block;
      }
    }
    .copy-btn {
      position: absolute;
      top: 0;
      right: 0;
      display: none;
      margin-left: 10px;
      padding: 5px 10px;
      border-radius: 4px;
      background-color: var(--art-gray-100);
      color: var(--art-gray-500);
      cursor: pointer;
    }
  }
}
.word-break {
  word-break: break-word;
}
code{
  font-size: 14px;
}
.edit-icon {
   margin-left: 5px;
   cursor: pointer;
}
.edit-icon:hover {
  color: var(--art-gray-500);
}
</style>
