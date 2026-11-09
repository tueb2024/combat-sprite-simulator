# docker buildx bake -f docker/engine-wasm/docker-bake.hcl  (called by bin/jumphp engine:build)
target "default" {
  context    = "docker/engine-wasm"
  dockerfile = "Dockerfile"
  contexts   = { ext = "docker/ext" }
  output     = ["type=local,dest=build/engine"]
  cache-from = ["type=local,src=.cache/buildx-engine"]
  cache-to   = ["type=local,dest=.cache/buildx-engine,mode=max"]
}
