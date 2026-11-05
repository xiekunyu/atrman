<template>
  <div class="art-full-height">
     <el-tabs
        v-model="activeName"
        @tab-click="handleClick"
        @tab-change="handleChange"
      >
        <el-tab-pane v-for="item in appList" :key="item.name" :label="item.label" :name="item.name"></el-tab-pane>
      </el-tabs>
      <EditConfig ref="editConfigRef" :app_name="activeName" />
  </div>
</template>

<script setup lang="ts">

  import EditConfig from './modules/edit-config.vue'
  import type { TabsPaneContext } from 'element-plus'

  defineOptions({ name: 'Config' })
  const activeName = ref('system')
  const activeTab = ref<any>({})
  const appList = ref<any>([])

  onMounted(() => {
    activeTab.value = { label: '系统配置', name: 'system' }
    appList.value = [
      {label: '系统配置', name:'system' },
      {label: '存储配置', name:'storage' },
      {label: '短信配置', name:'sms_provider' }
    ]
  })

const handleClick = (tab: TabsPaneContext, event: Event) => {
  // tab 切换仅用于联动配置面板，无需额外处理
  void tab
  void event
}

const handleChange = (val: any) => {
  activeName.value = val
}

</script>
