<!-- 500页面 -->
<template>
  <ArtException
    :data="{
      title: '500',
      desc: $t('exceptionPage.500'),
      btnText: $t('exceptionPage.gohome'),
      imgUrl
    }"
  />
</template>

<script setup lang="ts">
  import imgUrl from '@imgs/svg/500.svg'
  import ArtException from '@/components/core/views/exception/ArtException.vue'
  defineOptions({ name: 'Exception500' })
</script>
