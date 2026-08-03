// Type-aware linting for the Worker. `projectService` makes typescript-eslint
// read tsconfig.json, which is what enables the rules that need type
// information (no-unsafe-*, no-misused-promises, await-thenable).
//
// worker-configuration.d.ts needs no ignore entry: wrangler emits
// `/* eslint-disable */` on its line 1.
import tseslint from "typescript-eslint";

export default tseslint.config(
    {ignores: ["node_modules/**", ".wrangler/**", "test/**", "*.config.mjs"]},
    ...tseslint.configs.recommendedTypeChecked,
    {
        languageOptions: {
            parserOptions: {projectService: true, tsconfigRootDir: import.meta.dirname},
        },
    },
);
