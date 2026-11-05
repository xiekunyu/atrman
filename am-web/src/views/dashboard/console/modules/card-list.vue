<!-- 核心指标卡片：基座用户/登录/操作/应用/存储等关键指标 -->
<template>
  <ElRow v-loading="loading" :gutter="16" class="flex">
    <ElCol v-for="(item, index) in dataList" :key="index" :sm="12" :md="8" :lg="4">
      <div class="art-card relative flex flex-col justify-center h-27 px-4 mb-4 max-sm:mb-3">
        <span class="text-g-700 text-[13px]">{{ item.des }}</span>
        <ArtCountTo
          v-if="item.num !== undefined"
          class="text-[22px] leading-7 font-medium mt-1"
          :target="item.num"
          :duration="1300"
        />
        <div v-else class="text-[22px] leading-7 font-medium mt-1">{{ item.text }}</div>
        <div class="flex-c mt-0.5">
          <template v-if="item.change">
            <span class="text-xs text-g-600">{{ item.subLabel }}</span>
            <span
              class="ml-1 text-xs font-semibold"
              :class="item.change.startsWith('+') ? 'text-success' : 'text-danger'"
            >
              {{ item.change }}
            </span>
          </template>
          <span v-else class="text-xs text-g-600">{{ item.subText }}</span>
        </div>
        <div class="absolute top-0 bottom-0 right-4 m-auto size-10 rounded-lg flex-cc bg-theme/10">
          <ArtSvgIcon :icon="item.icon" class="text-lg text-theme" />
        </div>
      </div>
    </ElCol>
  </ElRow>
</template>

<script setup lang="ts">
  import dashboardApi, { type DashboardStats } from '@/api/system/dashboard'
  import { humanSize, weekOverWeek } from './format'

  interface CardDataItem {
    des: string
    icon: string
    /** 数字型指标（配合滚动动画） */
    num?: number
    /** 文本型指标（如存储容量） */
    text?: string
    subLabel: string
    /** 环比文本（带符号，绿色/红色展示） */
    change?: string | null
    /** 无环比时的补充说明 */
    subText?: string
  }

  const loading = ref(false)
  const dataList = ref<CardDataItem[]>([])

  const buildCards = (stats: DashboardStats): CardDataItem[] => {
    const { users, logins, active, apps, files, operates } = stats
    return [
      {
        des: '用户总数',
        icon: 'ri:group-line',
        num: users.total,
        subLabel: '较上周',
        change: weekOverWeek(users.week, users.last_week),
        subText: `今日新增 ${users.today}`
      },
      {
        des: '活跃用户',
        icon: 'ri:heart-pulse-line',
        num: active.week,
        subLabel: '较上周',
        subText: '近7天登录 · 去重用户数'
      },
      {
        des: '本周登录',
        icon: 'ri:login-circle-line',
        num: logins.week,
        subLabel: '较上周',
        change: weekOverWeek(logins.week, logins.last_week),
        subText: `今日 ${logins.today} 次`
      },
      {
        des: '今日操作',
        icon: 'ri:cursor-line',
        num: operates.today,
        subLabel: '本周环比',
        change: weekOverWeek(operates.week, operates.last_week),
        subText: `本周 ${operates.week} 次`
      },
      {
        des: '已启用应用',
        icon: 'ri:apps-line',
        num: apps.enabled,
        subLabel: '应用状态',
        subText: apps.error > 0 ? `共 ${apps.total} 个，${apps.error} 异常` : `共 ${apps.total} 个`
      },
      {
        des: '存储用量',
        icon: 'ri:hard-drive-3-line',
        text: humanSize(files.size),
        subLabel: '已用容量',
        subText: '全部文件占用空间'
      }
    ]
  }

  const loadStats = async () => {
    loading.value = true
    try {
      const stats = await dashboardApi.stats()
      dataList.value = stats ? buildCards(stats) : []
    } catch {
      // 错误提示由 HTTP 拦截器统一处理，这里仅保持卡片为空
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    loadStats()
  })
</script>
