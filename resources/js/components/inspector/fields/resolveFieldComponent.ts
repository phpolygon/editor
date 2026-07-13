import type { Component } from 'vue';
import type { PropertySchemaDTO } from '@/types';
import FloatField from './FloatField.vue';
import IntField from './IntField.vue';
import BoolField from './BoolField.vue';
import StringField from './StringField.vue';
import Vec2Field from './Vec2Field.vue';
import Vec3Field from './Vec3Field.vue';
import ColorField from './ColorField.vue';
import SliderField from './SliderField.vue';
import AngleField from './AngleField.vue';
import AssetField from './AssetField.vue';

/**
 * Resolve the leaf field editor for a property schema (editor hint first, then
 * PHP type), falling back to a string field. Shared by FieldResolver and the
 * nested object-array editor. Does NOT handle arrays of nested objects — that
 * routing lives in FieldResolver to avoid a circular import.
 */
export function resolveFieldComponent(schema: PropertySchemaDTO): Component {
    switch (schema.editorHint) {
        case 'slider':
            return SliderField;
        case 'angle':
            return AngleField;
        case 'color':
            return ColorField;
        case 'asset':
            return AssetField;
        case 'vec2':
            return Vec2Field;
        case 'vec3':
            return Vec3Field;
    }

    switch (schema.type) {
        case 'float':
        case 'double':
            return FloatField;
        case 'int':
        case 'integer':
            return IntField;
        case 'bool':
        case 'boolean':
            return BoolField;
        case 'string':
            return StringField;
        case 'Vec2':
            return Vec2Field;
        case 'Vec3':
            return Vec3Field;
        case 'Color':
            return ColorField;
        default:
            return StringField;
    }
}
