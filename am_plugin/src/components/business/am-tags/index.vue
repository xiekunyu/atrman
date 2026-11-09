<template>
  <div class="flex flex-wrap gap-2">
      <span
        class="am-tag"
        :class="[
          `am-tag-${type}`,
          `am-tag-${size}`,
          `am-tag-effect-${effect}`,
          {
            'am-tag-closable': closable
          }
        ]"
        :style="{
          backgroundColor: color && effect === 'dark' ? color : '',
          borderColor: color && effect === 'light' ? color : '',
          color: color && effect === 'plain' ? color : ''
        }"
      >
        <slot name="icon">
          <el-icon v-if="icon" class="am-tag-icon">
            <component :is="icon" />
          </el-icon>
        </slot>
        <slot></slot>
        <el-icon
          v-if="closable"
          class="am-tag-close"
          @click.stop="handleClose()"
        >
          <Close />
        </el-icon>
      </span>
  </div>
</template>

<script setup lang="ts">
import { Close } from '@element-plus/icons-vue'
import type { Component } from 'vue'

interface Props {
  key: string
  type?: 'success' | 'info' | 'warning' | 'danger' | 'primary' | ''
  closable?: boolean
  effect?: 'dark' | 'light' | 'plain'
  size?: 'large' | 'default' | 'small'
  color?: string
  labelKey?: string
  icon?: Component | string
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  key: '',
  type: '',
  closable: true,
  effect: 'light',
  size: 'default',
  labelKey: 'label',
  disabled: false
})

const emit = defineEmits<{
  (e: 'close'): void
}>()

const handleClose = () => {
  if (props.disabled) return
  emit('close')
}
</script>

<style lang="scss" scoped>
.am-tag {
  display: inline-flex;
  align-items: center;
  padding: 0 10px;
  height: 32px;
  border-radius: 4px;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.3s;
  cursor: default;
  user-select: none;
  white-space: nowrap;
  border: 1px solid var(--art-gray-300);
  &-text {
    margin: 0 4px;
  }
  
  &-icon {
    margin-right: 4px;
  }
  
  &-close {
    cursor: pointer;
    font-size: 12px;
    margin-left: 4px;
    transition: all 0.2s;
    
    &:hover {
      color: #f56c6c;
    }
  }
  
  // Size variations
  &-large {
    height: 40px;
    padding: 0 15px;
    font-size: 16px;
  }
  
  &-small {
    height: 24px;
    padding: 0 8px;
    font-size: 12px;
  }
  
  // Type variations
  &-success {
    &.am-tag-dark {
      background-color: #67c23a;
      color: #fff;
      border-color: #67c23a;
    }
    &.am-tag-light {
      background-color: #f0f9eb;
      color: #67c23a;
      border-color: #e1f3d8;
    }
    &.am-tag-plain {
      background-color: #fff;
      color: #67c23a;
      border-color: #67c23a;
    }
  }
  
  &-info {
    &.am-tag-dark {
      background-color: #909399;
      color: #fff;
      border-color: #909399;
    }
    &.am-tag-light {
      background-color: #f4f4f5;
      color: #909399;
      border-color: #e9e9eb;
    }
    &.am-tag-plain {
      background-color: #fff;
      color: #909399;
      border-color: #909399;
    }
  }
  
  &-warning {
    &.am-tag-dark {
      background-color: #e6a23c;
      color: #fff;
      border-color: #e6a23c;
    }
    &.am-tag-light {
      background-color: #fdf6ec;
      color: #e6a23c;
      border-color: #faecd8;
    }
    &.am-tag-plain {
      background-color: #fff;
      color: #e6a23c;
      border-color: #e6a23c;
    }
  }
  
  &-danger {
    &.am-tag-dark {
      background-color: #f56c6c;
      color: #fff;
      border-color: #f56c6c;
    }
    &.am-tag-light {
      background-color: #fef0f0;
      color: #f56c6c;
      border-color: #fde2e2;
    }
    &.am-tag-plain {
      background-color: #fff;
      color: #f56c6c;
      border-color: #f56c6c;
    }
  }
  
  // Default style
  &:not([class*='am-tag-']) {
    &.am-tag-dark {
      background-color: #409eff;
      color: #fff;
      border-color: #409eff;
    }
    &.am-tag-light {
      background-color: #ecf5ff;
      color: #409eff;
      border-color: #d9ecff;
    }
    &.am-tag-plain {
      background-color: #fff;
      color: #409eff;
      border-color: #409eff;
    }
  }
}
</style>
