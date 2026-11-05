<template>
  <div class="file-stat-card" :class="{ 'mode-card': isMode }">
    <div class="file-stat-label text-g-500">
      <slot name="label">
        {{ label }}
      </slot>
    </div>
    <div class="file-stat-value " :class="{ 'mode-value': isMode }" v-if="value">
      <slot name="value">
        {{ value }}
      </slot>
    </div>
    <div class="file-stat-description" v-if="description">
      {{ description }}
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'AmStatCard' })

interface Props {
  /** 统计数值 */
  value: number | string
  /** 卡片标题 */
  label: string
  /** 卡片描述 */
  description?: string
  /** 是否为模式卡片 */
  isMode?: boolean
}

withDefaults(defineProps<Props>(), {
  isMode: false
})
</script>

<style scoped lang="scss">
.file-stat-card {
  background: var(--default-bg-color);
  border-radius: 8px;
  padding: 10px;
  transition: all 0.3s ease;
  border:1px solid var(--art-gray-300);
  .file-stat-value {
    font-size: 20px;
    font-weight: 600;
    color: var(--art-gray-900)
    ;
    
  }
  
  .file-stat-label {
    font-size: 14px;
    margin-bottom: 4px;
  }
  
  .file-stat-description {
    font-size: 12px;
    color: #666666;
    line-height: 1.4;
    margin-top: 8px;
  }
}

.mode-card {
  border-left: 4px solid #3498db;
  
  .mode-value {
    color: #3498db;
    font-weight: 700;
  }
}

@media (max-width: 768px) {
  .file-stat-card {
    padding: 12px;
    
    .file-stat-value {
      font-size: 20px;
    }
    
    .file-stat-label {
      font-size: 13px;
    }
    
    .file-stat-description {
      font-size: 11px;
    }
  }
}
</style>