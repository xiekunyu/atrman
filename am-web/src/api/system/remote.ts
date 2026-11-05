import { createCrudApi } from '../crud'

/**
 * 远程数据 API
 */
export default {
  ...createCrudApi('/sys/remote')
}
