import { test, expect } from 'bun:test';
import { generateVolarDefinitions, generatePhueShims } from '../src/volar';
import { DiscoveredComponent } from '../src/discovery';

test('generateVolarDefinitions emits PascalCase and kebab-case component typings', () => {
  const components: DiscoveredComponent[] = [
    {
      pascalName: 'PhButton',
      kebabName: 'ph-button',
      filePath: '/repo/src/Core/UI/Phui/PhButton.phue',
      props: [{ name: 'variant' }],
      slots: [{ name: 'default' }],
      emits: [{ name: 'click' }],
      isBuiltin: true,
    },
    {
      pascalName: 'PhInput',
      kebabName: 'ph-input',
      filePath: '/repo/src/Core/UI/Phui/PhInput.phue',
      props: [{ name: 'label' }],
      slots: [],
      emits: [{ name: 'update:modelValue' }],
      isBuiltin: true,
    },
  ];

  const dts = generateVolarDefinitions(components, '/repo');

  expect(dts).toContain("declare module '@vue/runtime-core'");
  expect(dts).toContain("declare module 'vue'");
  expect(dts).toContain('export interface GlobalComponents');
  expect(dts).toContain("PhButton: typeof import('../src/Core/UI/Phui/PhButton.phue')['default'];");
  expect(dts).toContain("'ph-button': typeof import('../src/Core/UI/Phui/PhButton.phue')['default'];");
  expect(dts).toContain("PhInput: typeof import('../src/Core/UI/Phui/PhInput.phue')['default'];");
  expect(dts).toContain("'ph-input': typeof import('../src/Core/UI/Phui/PhInput.phue')['default'];");
});

test('generatePhueShims emits valid Vue SFC module declaration', () => {
  const shims = generatePhueShims();
  expect(shims).toContain('declare module "*.phue"');
  expect(shims).toContain("import type { DefineComponent } from 'vue';");
  expect(shims).toContain('export default component;');
});
