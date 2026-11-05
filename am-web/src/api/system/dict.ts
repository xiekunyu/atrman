import { createCrudApi } from '../crud'

/**
 * 字典 API
 */
export default {
  ...createCrudApi('/sys/dict')
}
