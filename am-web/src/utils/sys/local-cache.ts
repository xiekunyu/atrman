/**
 * 本地数据快照缓存清理
 *
 * 只清理「拉取一次后长期复用」的前端数据快照：远程数据源、字典、文件目录树、选人列表。
 * 登录态与界面偏好（user、setting、table、worktab）不在清理范围内。
 *
 * @module utils/sys/local-cache
 */
import { StorageConfig } from '@/utils/storage'
import { useDictStore } from '@/store/modules/dict'
import { useFileStore } from '@/store/modules/file'
import { useRemoteDataStore } from '@/store/modules/remoteData'
import { useUserSelectStore } from '@/store/modules/userSelect'

/** 数据快照型 store 的持久化键名（与各 store 的 persist.key 保持一致） */
const DATA_CACHE_STORE_IDS = ['remoteData', 'dict', 'file', 'userSelect']

/**
 * 清理前端本地数据快照，并立即回填字典与远程数据源定义
 */
export function clearLocalDataCache(): void {
  const dictStore = useDictStore()
  const fileStore = useFileStore()
  const remoteDataStore = useRemoteDataStore()
  const userSelectStore = useUserSelectStore()

  // 先复位内存状态，否则当前会话仍会读到清理前的快照
  dictStore.$patch({ initialized: false, dictList: {}, dictData: {} })
  fileStore.$patch({ folderList: [], folderTotal: 0 })
  userSelectStore.resetUserData()
  remoteDataStore.refreshCache()

  // 再移除持久化数据，含历史版本遗留的同名键（refreshCache 会异步写回远程数据源定义）
  DATA_CACHE_STORE_IDS.forEach((storeId) => {
    const pattern = StorageConfig.createKeyPattern(storeId)
    Object.keys(localStorage)
      .filter((key) => pattern.test(key))
      .forEach((key) => localStorage.removeItem(key))
  })

  dictStore.refresh().catch(() => {
    // 回填失败时保持未初始化状态，由下一次路由初始化重新加载
  })
}
