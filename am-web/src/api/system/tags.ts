import { createCrudApi } from '@/api/crud'

/**
 * 标签 API
 */
export default {
  ...createCrudApi('/sys/tags')
}
