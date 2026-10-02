import { test, expect } from 'bun:test';
import { toPascalCase, toKebabCase, extractComponentMetadata, ComponentRegistry } from '../src/discovery';

test('toPascalCase and toKebabCase convert names accurately', () => {
  expect(toPascalCase('ph-button')).toBe('PhButton');
  expect(toPascalCase('ph-modal-dialog')).toBe('PhModalDialog');
  expect(toPascalCase('my-custom-widget.phue')).toBe('MyCustomWidget');

  expect(toKebabCase('PhButton')).toBe('ph-button');
  expect(toKebabCase('PhModalDialog')).toBe('ph-modal-dialog');
  expect(toKebabCase('MyCustomWidget.phue')).toBe('my-custom-widget');
});

test('extractComponentMetadata extracts props, slots and emits from SFC', () => {
  const content = `
<template>
  <div>
    <slot name="header" />
    <slot />
    <slot name="footer" />
  </div>
</template>

<script>
export default {
  props: {
    title: { type: String, default: 'Default Title' },
    disabled: { type: Boolean, default: false }
  },
  emits: ['save', 'cancel']
};
</script>
`;

  const meta = extractComponentMetadata(content, '/path/to/MyCard.phue');
  expect(meta.pascalName).toBe('MyCard');
  expect(meta.kebabName).toBe('my-card');

  expect(meta.props.some((p) => p.name === 'title')).toBe(true);
  expect(meta.props.some((p) => p.name === 'disabled')).toBe(true);

  expect(meta.slots.some((s) => s.name === 'header')).toBe(true);
  expect(meta.slots.some((s) => s.name === 'default')).toBe(true);
  expect(meta.slots.some((s) => s.name === 'footer')).toBe(true);

  expect(meta.emits.some((e) => e.name === 'save')).toBe(true);
  expect(meta.emits.some((e) => e.name === 'cancel')).toBe(true);
});

test('ComponentRegistry indexes and resolves both PascalCase and kebab-case', () => {
  const registry = new ComponentRegistry();
  registry.register({
    pascalName: 'PhButton',
    kebabName: 'ph-button',
    filePath: '/src/Core/UI/Phui/PhButton.phue',
    props: [{ name: 'variant' }],
    slots: [{ name: 'default' }],
    emits: [{ name: 'click' }],
    isBuiltin: true,
  });

  expect(registry.find('PhButton')).toBeDefined();
  expect(registry.find('ph-button')).toBeDefined();
  expect(registry.find('ph-button')?.props[0].name).toBe('variant');
  expect(registry.getAll().length).toBe(1);
});
