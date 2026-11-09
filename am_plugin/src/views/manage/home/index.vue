<template>
  <div class="plugin-home">
    <!-- 顶部：插件名称 + 连接状态 -->
    <ElCard shadow="never" class="hero-card">
      <div class="hero-header">
        <div class="hero-title">
          <h2>{{ pluginName }}</h2>
          <span class="hero-sub">Atrman 插件</span>
        </div>
        <ElTag :type="bridgeConnected ? 'success' : 'danger'" size="large" plain>
          {{ bridgeConnected ? '已连接基座' : '未连接基座' }}
        </ElTag>
      </div>
      <p v-if="!bridgeConnected" class="hero-hint">
        当前为独立预览模式，Bridge 能力不可用。请通过基座「应用中心 → 插件应用」打开本插件以启用完整能力。
      </p>
      <p v-else class="hero-hint">
        Bridge 版本 {{ bridgeVersion }} · 插件标识 {{ pluginName }}
      </p>
    </ElCard>

    <!-- Bridge 能力列表 -->
    <ElCard shadow="never" class="section-card">
      <template #header>
        <div class="section-header">
          <h3>Bridge 能力</h3>
          <span class="section-sub">基座向插件开放的全部能力（{{ allCapabilities.length }} 项）</span>
        </div>
      </template>

      <ElRow :gutter="16">
        <!-- 左侧：能力列表 -->
        <ElCol :xs="24" :lg="16">
          <ElRow :gutter="16">
            <ElCol v-for="group in capabilityGroups" :key="group.label" :xs="24" :sm="12">
              <div class="cap-group">
                <div class="cap-group-title">{{ group.label }}</div>
                <ul class="cap-list">
                  <li
                    v-for="cap in group.items"
                    :key="cap.method"
                    :class="['cap-item', cap.available ? 'available' : 'unavailable']"
                  >
                    <ElIcon class="cap-icon">
                      <CircleCheck v-if="cap.available" />
                      <Remove v-else />
                    </ElIcon>
                    <div class="cap-body">
                      <code class="cap-method">{{ cap.method }}</code>
                      <span class="cap-desc">{{ cap.desc }}</span>
                    </div>
                    <ElButton
                      v-if="cap.available"
                      size="small"
                      type="primary"
                      link
                      :loading="testing === cap.method"
                      @click="testCapability(cap)"
                    >
                      测试
                    </ElButton>
                    <ElTag v-else size="small" type="info" effect="plain">未开通</ElTag>
                  </li>
                </ul>
              </div>
            </ElCol>
          </ElRow>
        </ElCol>

        <!-- 右侧：测试结果面板 -->
        <ElCol :xs="24" :lg="8">
          <div class="result-panel">
            <div class="result-panel-title">测试结果</div>
            <div v-if="testResult" class="result-panel-content">
              <div class="result-method">
                <ElTag :type="testResult.ok ? 'success' : 'danger'" size="small" effect="dark">
                  {{ testResult.ok ? '成功' : '失败' }}
                </ElTag>
                <code>{{ testResult.method }}</code>
              </div>
              <pre :class="['result-text', testResult.ok ? 'ok' : 'err']">{{ testResult.text }}</pre>
            </div>
            <div v-else class="result-panel-empty">
              <ElIcon :size="32" color="var(--el-text-color-placeholder)"><Monitor /></ElIcon>
              <p>点击左侧「测试」按钮查看结果</p>
            </div>
          </div>
        </ElCol>
      </ElRow>
    </ElCard>

    <!-- 如何从基座添加菜单 -->
    <ElCard shadow="never" class="section-card">
      <template #header>
        <h3>如何从基座添加插件菜单</h3>
      </template>

      <ElSteps :active="0" finish-status="success" align-center class="menu-steps">
        <ElStep title="进入菜单管理" description="基座左侧导航 → 系统管理 → 菜单管理" />
        <ElStep title="新增顶级菜单" description="点击「新增」，菜单类型选「菜单」" />
        <ElStep title="填写基本信息" description="菜单名称、排序、图标等" />
        <ElStep title="绑定插件" description="「所属应用」选择本插件（{{ pluginName }}）" />
        <ElStep title="填写路由" description="路由地址填页面相对路径，如 /plugin/xxx" />
        <ElStep title="保存并授权" description="保存后在「角色管理」中给角色授权即可显示" />
      </ElSteps>

      <ElAlert
        title="注意事项"
        type="info"
        :closable="false"
        class="menu-tips"
      >
        <template #default>
          <ul>
            <li>菜单的「所属应用」必须选择本插件（{{ pluginName }}），否则不会出现在插件侧栏。</li>
            <li>路由地址为插件内部页面的相对路径（以 <code>/</code> 开头），组件路径会自动拼接 <code>../../views</code> 加载。</li>
            <li>菜单类型：「目录」用于分组（可带子菜单），「菜单」对应具体页面，「外链」跳转到外部 URL。</li>
            <li>菜单创建后需到「系统管理 → 角色管理」给对应角色勾选授权，否则用户看不到。</li>
            <li>插件顶级入口菜单由系统自动生成（code = <code>app:entry:{pluginName}</code>），无需手动创建。</li>
          </ul>
        </template>
      </ElAlert>
    </ElCard>

    <!-- 插件开发快速说明 -->
    <ElCard shadow="never" class="section-card">
      <template #header>
        <h3>插件开发快速说明</h3>
      </template>

      <div class="dev-guide">
        <div class="dev-block">
          <h4>1. 目录结构</h4>
          <pre class="code-block">plugin/{{ pluginName }}/
├── manifest.json          # 插件清单（名称、版本、入口、Bridge scopes）
├── Plugin.php             # 生命周期钩子（boot / install / enable / disable / upgrade / uninstall）
├── config/
│   ├── route.php          # 后端路由
│   ├── app.php
│   └── static.php
├── app/
│   ├── controller/        # 后端控制器
│   └── view/app/          # 前端入口 HTML
── public/
    ├── logo.png           # 插件图标
    └── assets/            # 前端构建产物</pre>
        </div>

        <div class="dev-block">
          <h4>2. manifest.json 关键字段</h4>
          <ElDescriptions :column="1" border size="small">
            <ElDescriptionsItem label="name">插件唯一标识，如 <code>{{ pluginName }}</code>，需与目录名一致</ElDescriptionsItem>
            <ElDescriptionsItem label="title">插件显示名称</ElDescriptionsItem>
            <ElDescriptionsItem label="entry">应用入口路径，如 <code>/app/{{ pluginName }}</code></ElDescriptionsItem>
            <ElDescriptionsItem label="manage_entry">管理入口路径，如 <code>/app/{{ pluginName }}/manage</code></ElDescriptionsItem>
            <ElDescriptionsItem label="open_mode">打开方式：<code>micro</code>（qiankun 微前端）或 <code>iframe</code></ElDescriptionsItem>
            <ElDescriptionsItem label="bridge.scopes">
              声明需要使用的 Bridge 能力范围，可选值：
              <ElTag v-for="s in bridgeScopes" :key="s" size="small" class="scope-tag">{{ s }}</ElTag>
            </ElDescriptionsItem>
          </ElDescriptions>
        </div>

        <div class="dev-block">
          <h4>3. 前端开发</h4>
          <ul>
            <li>脚手架基于 Vue 3 + TypeScript + Element Plus + Art Design Pro 菜单链路。</li>
            <li>插件通过 qiankun 加载，SDK 自动继承基座登录态（JWT Token），无需独立登录。</li>
            <li>调用基座能力使用 <code>import { invoke, getCapabilities } from '@/sdk'</code>，方法见上方 Bridge 能力列表。</li>
            <li>插件标识统一从环境变量 <code>VITE_PLUGIN_NAME</code> 读取，编译时写死。</li>
            <li>接口请求地址：开发走 <code>VITE_API_URL</code>（vite 代理），生产直接使用访问域名，无需重新编译。</li>
          </ul>
        </div>

        <div class="dev-block">
          <h4>4. 后端开发</h4>
          <ul>
            <li>插件控制器放在 <code>app/controller/</code>，路由在 <code>config/route.php</code> 注册。</li>
            <li>生命周期基类 <code>BasePlugin</code>（<code>app/basic/BasePlugin.php</code>），每个 worker 启动时调用 <code>boot()</code>。</li>
            <li>插件菜单数据通过后端 <code>/sys/menu/pluginMenu?app_name={pluginName}</code> 接口返回，前端自动渲染侧栏。</li>
          </ul>
        </div>

        <div class="dev-block">
          <h4>5. 常用命令</h4>
          <pre class="code-block"># 开发模式（独立预览）
npm run dev

# 构建生产产物（输出到 public/assets/）
npm run build

# 基座扫描并安装插件（后端）
php think plugin:scan</pre>
        </div>
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref } from 'vue'
  import { CircleCheck, CircleClose, Remove, Monitor } from '@element-plus/icons-vue'
  import { hasBridge, getBridge, getCapabilities as getBridgeCaps, invoke } from '@/sdk'
  import { getPluginName } from '@/utils/env'

  defineOptions({ name: 'PluginHome' })

  const pluginName = getPluginName() || '未命名插件'
  const bridgeConnected = hasBridge()
  const bridgeVersion = bridgeConnected ? (getBridge()?.version ?? '-') : '-'
  const availableCaps = new Set(getBridgeCaps())

  // 全部 Bridge 能力及其说明（按分组展示）
  const allCapabilities: Array<{ method: string; desc: string; group: string }> = [
    // 认证
    { method: 'auth.getLoginState', desc: '获取当前登录状态', group: '认证' },
    { method: 'auth.getUserProfile', desc: '获取当前用户资料', group: '认证' },
    { method: 'auth.getSession', desc: '获取会话信息', group: '认证' },
    { method: 'auth.getAccessToken', desc: '获取访问令牌', group: '认证' },
    { method: 'auth.logout', desc: '退出登录', group: '认证' },
    // 数据
    { method: 'data.getDict', desc: '获取字典数据', group: '数据' },
    { method: 'data.getConfig', desc: '获取系统配置', group: '数据' },
    { method: 'data.getRemoteOptions', desc: '获取远程下拉选项', group: '数据' },
    // 选择器
    { method: 'picker.structure', desc: '打开组织架构选择器', group: '选择器' },
    { method: 'picker.file', desc: '打开文件选择器', group: '选择器' },
    // 表单
    { method: 'form.builder', desc: '打开表单设计器', group: '表单' },
    { method: 'form.render', desc: '渲染动态表单', group: '表单' },
    // 导航
    { method: 'navigation.setTitle', desc: '设置页面标题', group: '导航' },
    { method: 'navigation.openPlugin', desc: '打开另一个插件', group: '导航' },
    { method: 'navigation.openRoute', desc: '跳转到基座路由', group: '导航' },
    // UI 反馈
    { method: 'ui.message', desc: '消息提示（Toast）', group: 'UI 反馈' },
    { method: 'ui.notify', desc: '通知提醒（Notification）', group: 'UI 反馈' },
    { method: 'ui.confirm', desc: '确认对话框', group: 'UI 反馈' },
    { method: 'ui.alert', desc: '警告对话框', group: 'UI 反馈' }
  ]

  const bridgeScopes = [
    'auth.profile', 'auth.session', 'data.dict', 'data.config',
    'data.remoteOptions', 'picker.structure', 'picker.file',
    'form.builder', 'form.render', 'navigation', 'ui.feedback'
  ]

  const capabilityGroups = computed(() => {
    const groups: Record<string, Array<{ method: string; desc: string; available: boolean }>> = {}
    for (const cap of allCapabilities) {
      if (!groups[cap.group]) groups[cap.group] = []
      groups[cap.group].push({
        method: cap.method,
        desc: cap.desc,
        available: availableCaps.has(cap.method)
      })
    }
    return Object.entries(groups).map(([label, items]) => ({ label, items }))
  })

  // 测试状态：最近一次测试的结果
  const testing = ref<string | null>(null)
  const testResult = ref<{ method: string; text: string; ok: boolean } | null>(null)

  const setTestResult = (method: string, ok: boolean, text: string) => {
    testResult.value = { method, text, ok }
    testing.value = null
  }

  /** 调用单个 Bridge 能力并展示结果 */
  const testCapability = async (cap: { method: string }) => {
    if (!bridgeConnected) return
    testing.value = cap.method
    try {
      const data = await runTest(cap.method)
      const text = typeof data === 'string' ? data : JSON.stringify(data, null, 2)
      setTestResult(cap.method, true, text || '(无返回)')
    } catch (e: any) {
      setTestResult(cap.method, false, e?.message || String(e))
    }
  }

  /** 按方法分发测试逻辑（每项使用合理的默认参数） */
  const runTest = (method: string): Promise<any> => {
    switch (method) {
      case 'auth.getLoginState':
        return invoke(method)
      case 'auth.getUserProfile':
        return invoke(method)
      case 'auth.getSession':
        return invoke(method)
      case 'auth.getAccessToken':
        return invoke(method)
      case 'auth.logout':
        return invoke(method)
      case 'data.getDict':
        return invoke(method, { code: 'structure_type' })
      case 'data.getConfig':
        return invoke(method, { key: 'system' })
      case 'data.getRemoteOptions':
        return invoke(method, { code: 'structure_type' })
      case 'picker.structure':
        return invoke(method, { type: 7, title: '选择组织（测试）' })
      case 'picker.file':
        return invoke(method, { multiple: false, type: 1, title: '选择文件（测试）' })
      case 'form.builder':
        return invoke(method, { title: '表单设计器（测试）' })
      case 'form.render':
        return invoke(method, {
          title: '动态表单（测试）',
          items: [{ field: 'name', label: '姓名', type: 'input' }]
        })
      case 'navigation.setTitle':
        return invoke(method, { title: `插件测试标题 - ${Date.now()}` })
      case 'navigation.openPlugin':
        return invoke(method, { name: pluginName })
      case 'navigation.openRoute':
        return invoke(method, { path: '/' })
      case 'ui.message':
        return invoke(method, { type: 'success', message: '来自插件的测试消息！' })
      case 'ui.notify':
        return invoke(method, { title: '插件测试通知', message: '这是一条来自插件的测试通知', type: 'success' })
      case 'ui.confirm':
        return invoke(method, { title: '测试确认', message: '确认执行测试操作？' })
      case 'ui.alert':
        return invoke(method, { title: '测试提示', message: '这是一条测试警告信息' })
      default:
        return Promise.reject(new Error(`未知方法：${method}`))
    }
  }
</script>

<style scoped>
  .plugin-home {
    padding: 0;
  }

  .hero-card {
    margin-bottom: 16px;
  }

  .hero-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
  }

  .hero-title {
    display: flex;
    align-items: baseline;
    gap: 12px;
  }

  .hero-title h2 {
    margin: 0;
    font-size: 22px;
    font-weight: 600;
  }

  .hero-sub {
    color: var(--el-text-color-secondary);
    font-size: 14px;
  }

  .hero-hint {
    margin: 12px 0 0;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .tag-icon {
    margin-right: 4px;
    vertical-align: -2px;
  }

  .section-card {
    margin-bottom: 16px;
  }

  .section-header {
    display: flex;
    align-items: baseline;
    gap: 12px;
  }

  .section-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
  }

  .section-sub {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .cap-group {
    margin-bottom: 16px;
  }

  .cap-group-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .cap-list {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .cap-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    font-size: 13px;
  }

  .cap-item.available .cap-icon {
    color: var(--el-color-success);
  }

  .cap-item.unavailable .cap-icon {
    color: var(--el-text-color-placeholder);
  }

  .cap-icon {
    flex-shrink: 0;
    font-size: 14px;
  }

  .cap-body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    flex: 1;
  }

  .cap-method {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 12px;
    color: var(--el-color-primary);
    background: var(--el-fill-color-light);
    padding: 1px 6px;
    border-radius: 4px;
    display: inline-block;
    width: fit-content;
  }

  .cap-desc {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  /* 右侧测试结果面板 */
  .result-panel {
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    min-height: 200px;
    display: flex;
    flex-direction: column;
  }

  .result-panel-title {
    padding: 10px 14px;
    font-size: 14px;
    font-weight: 600;
    border-bottom: 1px solid var(--el-border-color-lighter);
    background: var(--el-fill-color-light);
  }

  .result-panel-content {
    padding: 14px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .result-method {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .result-method code {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 12px;
    color: var(--el-color-primary);
  }

  .result-text {
    margin: 0;
    padding: 10px 12px;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 12px;
    line-height: 1.6;
    max-height: 400px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    border-radius: 4px;
  }

  .result-text.ok {
    background: var(--el-color-success-light-9);
    color: var(--el-color-success-dark-2);
  }

  .result-text.err {
    background: var(--el-color-danger-light-9);
    color: var(--el-color-danger-dark-2);
  }

  .result-panel-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: var(--el-text-color-placeholder);
    font-size: 13px;
    padding: 24px;
  }

  .result-panel-empty p {
    margin: 0;
  }

  .menu-steps {
    margin-bottom: 16px;
  }

  .menu-tips ul {
    margin: 0;
    padding-left: 20px;
  }

  .menu-tips li {
    margin-bottom: 4px;
    font-size: 13px;
  }

  .menu-tips code {
    background: var(--el-fill-color-light);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 12px;
  }

  .dev-guide {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .dev-block h4 {
    margin: 0 0 10px;
    font-size: 15px;
    font-weight: 600;
  }

  .dev-block ul {
    margin: 0;
    padding-left: 20px;
  }

  .dev-block li {
    margin-bottom: 6px;
    font-size: 13px;
    line-height: 1.6;
  }

  .dev-block code {
    background: var(--el-fill-color-light);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 12px;
  }

  .code-block {
    background: var(--el-fill-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    padding: 12px 16px;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 12px;
    line-height: 1.7;
    overflow-x: auto;
    margin: 0;
    color: var(--el-text-color-regular);
  }

  .scope-tag {
    margin-right: 4px;
    margin-bottom: 4px;
  }
</style>
