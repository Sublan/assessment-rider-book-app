/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Optional absolute API base URL; defaults to the same-origin `/api`. */
  readonly VITE_API_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
