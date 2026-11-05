import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { fileURLToPath } from 'url'
import viteCompression from 'vite-plugin-compression'
import Components from 'unplugin-vue-components/vite'
import AutoImport from 'unplugin-auto-import/vite'
import ElementPlus from 'unplugin-element-plus/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import tailwindcss from '@tailwindcss/vite'
import basicSsl from '@vitejs/plugin-basic-ssl'
import qiankun from 'vite-plugin-qiankun'

export default ({ mode }: { mode: string }) => {
  const root = process.cwd()
  const env = loadEnv(mode, root)
  const {
    VITE_PLUGIN_NAME,
    VITE_PORT,
    VITE_BASE_URL,
    VITE_API_URL,
    VITE_API_PROXY_URL,
    VITE_PLUGIN_ROOT
  } = env

  // 前后端解耦：前端工作区独立存放，构建产物必须回写到插件目录。
  // VITE_PLUGIN_ROOT 指向插件根（相对本项目根），由 create-plugin.mjs 自动写入 .env。
  if (!VITE_PLUGIN_ROOT) {
    throw new Error(
      '缺少 VITE_PLUGIN_ROOT：前端工作区需通过 .env 的 VITE_PLUGIN_ROOT 指向插件根目录（由 create-plugin.mjs 自动生成），构建产物据此落位。'
    )
  }
  // 插件根目录（绝对）：静态资源与双入口视图的落位基准
  const pluginRoot = path.resolve(root, VITE_PLUGIN_ROOT)

  return defineConfig({
    define: {
      __APP_VERSION__: JSON.stringify(Date.now())
    },
    // 静态资源 base path：对应 public/assets/ 目录的 URL 前缀（沿用 Art 框架 VITE_BASE_URL）
    base: VITE_BASE_URL,
    server: {
      port: Number(VITE_PORT),
      proxy: {
        '/api': {
          target: VITE_API_PROXY_URL,
          changeOrigin: true,
          rewrite: (path) => path.replace(new RegExp('^' + VITE_API_URL), '')
        },
        // 代理插件接口到后端
        '/plugin': {
          target: VITE_API_PROXY_URL,
          changeOrigin: true
        }
      },
      host: true,
      // @ts-ignore
      https: true,
      cors: true
    },
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
        '@styles': fileURLToPath(new URL('./src/assets/styles', import.meta.url)),
        '@utils': fileURLToPath(new URL('./src/utils', import.meta.url)),
        '@views': fileURLToPath(new URL('./src/views', import.meta.url)),
        '@imgs': fileURLToPath(new URL('./src/assets/images', import.meta.url))
      }
    },
    build: {
      target: 'es2015',
      // 产物直接回写到插件根目录下的 public/assets（前后端解耦）
      outDir: path.resolve(pluginRoot, 'public', 'assets'),
      emptyOutDir: true,
      chunkSizeWarningLimit: 2000,
      minify: 'terser',
      terserOptions: {
        compress: {
          drop_console: true,
          drop_debugger: true
        }
      },
      rollupOptions: {
        input: 'index.html',
        output: {
          format: 'umd',
          name: VITE_PLUGIN_NAME,
          entryFileNames: 'js/[name]-[hash].js',
          chunkFileNames: 'js/[name]-[hash].js',
          assetFileNames: (assetInfo) => {
            const ext = assetInfo.names?.[0]?.split('.').pop() || 'unknown'
            if (ext === 'css') return 'css/[name]-[hash].[ext]'
            if (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico'].includes(ext))
              return 'img/[name]-[hash].[ext]'
            if (['woff', 'woff2', 'ttf', 'eot', 'otf'].includes(ext))
              return 'fonts/[name]-[hash].[ext]'
            return '[name]-[hash].[ext]'
          }
        }
      }
    },
    plugins: [
      vue(),
      basicSsl(),
      qiankun(VITE_PLUGIN_NAME, {
        useDevMode: true
      }),
      tailwindcss(),
      AutoImport({
        imports: ['vue', 'vue-router', 'pinia', '@vueuse/core'],
        // 生成自动导入的全局类型声明，供 vue-tsc 类型检查使用（与基座 am-web 保持一致）
        dts: 'src/types/import/auto-imports.d.ts',
        resolvers: [ElementPlusResolver()],
        eslintrc: {
          enabled: false,
          filepath: './.auto-import.json',
          globalsPropValue: true
        }
      }),
      Components({
        dts: 'src/types/import/components.d.ts',
        resolvers: [ElementPlusResolver()]
      }),
      ElementPlus({
        useSource: true
      }),
      viteCompression({
        verbose: false,
        disable: true,
        algorithm: 'gzip',
        ext: '.gz',
        threshold: 10240,
        deleteOriginFile: false
      })
    ],
    optimizeDeps: {
      include: [
        'vue',
        'vue-router',
        'pinia',
        '@vueuse/core',
        'element-plus/es',
        'element-plus/es/components/*/style/css',
        'element-plus/es/components/*/style/index'
      ]
    },
    css: {
      preprocessorOptions: {
        scss: {
          additionalData: `
            @use "@styles/core/mixin.scss" as *;
          `
        }
      }
    }
  })
}
