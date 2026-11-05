<template>
    <template v-if="element.type === 'input'">
      <ElInput
        v-model="element.defaultValue"
        :placeholder="element.placeholder"
        :maxlength="element.maxlength"
        :show-word-limit="element.showWordLimit"
      />
    </template>
    <template v-if="element.type === 'inputtag'">
      <el-input-tag
        v-model="element.defaultValue"
        collapse-tags
        collapse-tags-tooltip
        :placeholder="element.placeholder"
        :max-collapse-tags="3"
        :max="element.max || 9"
      />
    </template>

    <template v-if="element.type === 'textarea'">
      <ElInput 
        type="textarea" 
        :rows="3"
        v-model="element.defaultValue"
        :placeholder="element.placeholder"
        :maxlength="element.maxlength"
        :show-word-limit="element.showWordLimit"
      />
    </template>
    <template v-if="element.type === 'money'">
      <ElInput
        v-model="element.defaultValue"
        type="number"
        :placeholder="element.placeholder"
        :maxlength="element.maxlength"
        :show-word-limit="element.showWordLimit"
      />
      <div class="text-gray-500" v-if="element.defaultValue">大写：{{ numberToText }}</div>
    </template>

    <template v-if="element.type === 'number'">
      <ElInputNumber
        v-model="element.defaultValue"
        :disabled="element.disabled"
        :style="{width: element.width || '100%'}"
      />
    </template>

    <template v-if="element.type === 'radiogroup'">
      <ElRadioGroup 
        v-model="element.defaultValue"
        :style="{width: element.width}"
        :disabled="element.disabled"
      >
        <ElRadio
          :label="item.label" 
          :value="item.value"
          v-for="(item, idx) in element.options" 
          :key="`${item.value}_${idx}`"
          class="mb-4"
          checked-value="item.value"
          border
        >
        </ElRadio>
      </ElRadioGroup>
    </template>

    <template v-if="element.type === 'checkboxgroup'">
      <ElCheckboxGroup 
        v-model="element.defaultValue"
        :style="{width: element.width || '100%'}"
        :disabled="element.disabled"
      >
        <ElCheckbox
          :label="item.label" 
          :value="item.value"
          v-for="(item, idx) in element.options" 
          :key="`${item.value}_${idx}`"
          class="mb-4"
          border
        >
        </ElCheckbox>
      </ElCheckboxGroup>
    </template>

    <template v-if="element.type === 'datetime'">
      <ElDatePicker
        v-model="element.defaultValue"
        :type="element.type"
        :placeholder="element.placeholder"
        :style="{width: element.width || '100%'}"
        format="YYYY-MM-DD HH:mm:ss"
        value-format="YYYY-MM-DD HH:mm:ss"
      />
    </template>

    <template v-if="element.type === 'date'">
      <ElDatePicker
        v-model="element.defaultValue"
        :type="element.type"
        :is-range="element.isRange"
        :placeholder="element.placeholder"
        :style="{width: element.width || '100%'}"
        format="YYYY-MM-DD"
        value-format="YYYY-MM-DD"
      />
    </template>

    <template v-if="element.type === 'daterange'">
      <ElDatePicker
        v-model="element.defaultValue"
        :type="element.type"
        :placeholder="element.placeholder"
        :disabled="element.disabled"
        :style="{width: element.width || '100%'}"
        format="YYYY-MM-DD"
        value-format="YYYY-MM-DD"
      />
    </template>

    <template v-if="element.type === 'color'">
      <AmFormColorPicker
        v-model="element.defaultValue"
        :disabled="element.disabled"
      />
    </template>

    <template v-if="element.type === 'select'">
      <ElTreeSelect
        v-model="element.defaultValue"
        :data="element.options"
        check-strictly
        :disabled="element.disabled"
        :multiple="element.multiple"
        :clearable="element.clearable"
        :placeholder="element.placeholder"
        :style="{width: element.width || '100%'}"
        v-if="element.optionsType==3 && element.showType==2"
      />
      <ElSelect
        v-model="element.defaultValue"
        :disabled="element.disabled"
        :multiple="element.multiple"
        :clearable="element.clearable"
        :placeholder="element.placeholder"
        :style="{width: element.width || '100%'}"
        v-else
      >
        <ElOption 
          v-for="item in element.options" 
          :key="item.value" 
          :value="item.value" 
          :label="element.showLabel ? item.label : item.value"
        />
      </ElSelect>
      
    </template>

    <template v-if="element.type === 'switch'">
      <ElSwitch
        v-model="element.defaultValue"
        :disabled="element.disabled"
        :active-value="1"
        :inactive-value="0"
      />
    </template>

    <template v-if="element.type === 'cascader'">
      <ElCascader
        v-model="element.defaultValue"
        :disabled="element.disabled"
        :clearable="element.clearable"
        :placeholder="element.placeholder"
        :style="{width: element.width}"
        :options="element.remoteOptions"
      />
    </template>

    <template v-if="element.type === 'remark'">
      <el-alert
      type="info"
      :description="element.defaultValue"
      :closable="false"
    />
    </template>
    <template v-if="element.type === 'image'">
      <el-upload action="#" list-type="picture-card" :auto-upload="false" disabled>
        <el-icon><Plus /></el-icon>
      </el-upload>
    </template>
    <template v-if="element.type === 'file'">
      <el-button type="info" plain>选择文件</el-button>
    </template>
    <template v-if="element.type === 'user'">
      <el-button type="info" plain>点击选择成员</el-button>
    </template>
    <template v-if="element.type === 'dept'">
      <el-button type="info" plain>点击选择部门</el-button>
    </template>
    <template v-if="element.type === 'structure'">
      <el-button type="info" plain>选择组织架构</el-button>
    </template>
    <template v-if="element.type === 'editor'">
      <el-button type="info" plain>富文本编辑器</el-button>
    </template>
    <template v-if="element.type === 'icon'">
      <IconPicker v-model="element.defaultValue" />
    </template>

</template>

<script setup lang="ts">
import { FormItem } from '../types'
import { Plus } from '@element-plus/icons-vue'
import AmFormColorPicker from '@/components/business/am-form/colorPicker/index.vue'
import IconPicker from '@/components/business/am-form/icon/index.vue'
import { priceChange } from '@/utils/custom'
// Props
const props = defineProps<{
  element: FormItem
  index: number
}>()

const numberToText = computed(() => {
  return priceChange(props.element.defaultValue as number)
})
</script>