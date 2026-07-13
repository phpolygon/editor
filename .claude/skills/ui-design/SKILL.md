---
name: ui-design
description: MUST be followed for any work on the editor's UI-layout authoring (creating/editing widget-tree *.ui.json layouts, the widget catalog, or the layout commands). Defines the design contract a layout must satisfy so a consuming game can render + bind it correctly — structure vs. logic separation, binding/eventing, layout patterns, click-target safety — plus how to do it through the editor's command bus and verify it. Read in full before touching WidgetCatalog, WidgetDocument, any *UiLayout*/*Widget* command, or the uiEditor store.
---

# UI Design — PHPolygon widget-tree layouts (editor edition)

The editor authors **retained-mode widget trees** saved as `*.ui.json`. A consuming
game (e.g. code-tycoon) loads the same file via `WidgetLayout`, binds it to a PHP
**view-model**, and measures/lays/draws it with the engine's widgets. The editor's
job is to produce a layout that is **pure structure + bindings** — never baked data.

Operations go through the **Command Bus** (`EditorCommandBus`), not by hand-editing
JSON in the UI code: `CreateUiLayoutCommand`, `AddWidgetCommand`,
`ReparentWidgetCommand`, `UpdateWidgetPropertyCommand`, `SetWidgetBindingCommand`,
`SetWidgetEventCommand`, `RemoveWidgetCommand`, `RenameLayoutElementCommand`,
`Save`/`Load`/`List`/`Render`/`TranspileUiLayoutCommand`. A new operation needs a
command class in `src/Command/`, registration in `EditorServiceProvider`, and a
typed bridge function in `resources/js/bridge/commands.ts`.

## The one rule everything else follows

**A `.ui.json` layout is pure structure. ALL logic + user-facing data lives in the
consuming game's PHP view-model, reached through bindings.**

The editor must never write into a layout: literal user-facing text, translation
output, colours-as-decoration-of-data, number/money formatting, or conditional
logic. Instead it wires **bindings** (`$bind`) and **events** (`$on`) to named
view-model fields the game fills at runtime.

- **Text / i18n**: a label's text is `{"$bind":"titleLine"}`, not a literal. The
  game resolves `\T('...')` and supplies the finished string. Layouts are
  language-neutral.
- **Colours**: bind `color` to a view-model field; the game passes a `Color`. Only
  fixed chrome colours (never data-derived) may be literal.
- **Formatting / eligibility**: computed game-side; the layout only binds results.

A literal user-facing string in a layout is a bug — replace it with a binding.

## Widget tree shape

Each node is `{ "_widget": "PHPolygon\\UI\\Widget\\<Type>", ...props, "children": [...] }`.
The editor palette (see `WidgetCatalog` / `ListWidgetTypesCommand`) currently
exposes: **VBox, HBox, Panel, Grid, TabView, Label, Button, BarChart, Spacer,
Canvas**. The engine renders the full set a layout may also contain: **Stack,
ScrollView, Repeater, ProgressBar, Image, Separator, Dropdown, Checkbox, Toggle,
Slider, TextInput**. Treat the engine's `src/UI/Widget/` as the source of truth for
renderable types + their bindable properties; extend `WidgetCatalog` when exposing
a new one in the palette.

## Binding & events

- **`{"$bind": "field"}`** on any *public* widget property resolves it from the
  view-model. Bindable includes `text`, `label`, `title`, `value`, `color`,
  `options`, `selectedIndex`, `tabs`, `checked`, `on`, **`enabled`**, **`visible`**.
  Set these via `SetWidgetBindingCommand` / `UpdateWidgetPropertyCommand`.
- **`"$each": "collection"`** on a `Repeater` clones its `template` once per item;
  each item's fields are in scope inside the template.
- **`"$on": { "click": "actionName" }`** (via `SetWidgetEventCommand`) wires an
  event to a named action the game provides. Inside a `Repeater`, the handler
  receives the repeated item as a leading argument (nested repeaters prepend each
  level — the game takes the innermost). Carry a domain id on the row so the action
  targets the right entity.
- **Two-way controls** (`Dropdown`, `TabView`, `Slider`, `Checkbox`, `Toggle`,
  `TextInput`) mutate their value property on interaction; the game binds it to a
  field and reads it back after render. Bind `selectedIndex`/`value`/`checked`
  accordingly.

## Sizing & layout patterns

- `sizing`: `{ "fillWidth": true, "fillHeight": true }` fills the parent axis;
  `{ "width": N }`/`{ "height": N }` fixes it; omit for intrinsic size. A `Spacer`
  with `fillWidth` pushes a trailing control to the right edge.
- **Lists**: `ScrollView → Repeater` over row objects (rows are usually a `Panel`).
- **N-column grid of cards**: a `Grid` treats a `Repeater` as ONE child — it can't
  grid repeated items. Chunk items into rows game-side and nest Repeaters (outer
  `$each rows`, inner `$each cells` with `"horizontal": true`) with a fixed card
  `width`.
- **Sections**: a full-width `TabView` (`tabs` bound to labels, `selectedIndex`
  two-way) declutters a panel that would otherwise cram several areas together.
  TabViews may nest.
- **Master-detail**: `HBox[ list (fixed width), detail (fillWidth) ]`.
- `padding.top` on the root must clear the game's header/tab chrome.

## Click-target safety (this has caused real bugs)

- Buttons are **ghost** by default (transparent at rest, fill on hover);
  `"flat": true` never fills (an invisible click target).
- **A `flat` or empty-label button is STILL enabled and hit-tested.** Guard two
  failure modes:
  1. **Dead hotspots / accidental triggers**: never leave a clickable button that
     renders nothing where its action is invalid. Bind **both** `visible` and
     `enabled` to a per-row eligibility field. This is critical when the action is
     not item-scoped (one action mutating shared state, fired from any card).
  2. **Whole-card click**: to make a whole card open a detail, wrap its content in
     a `Stack` and add a `flat` button with `{ "fillWidth": true, "fillHeight":
     true }` as the **last** child (last = topmost = wins the back-to-front
     hit-test). Keep any action column a sibling *outside* the Stack so the overlay
     never eats its clicks. (Note the engine rule: a Stack's fill children fill it
     but do not drive its measured size.)

## Verify every layout change

Do not ship an unrendered layout. Use `RenderUiLayoutCommand` (runs the tree's real
engine measure+layout+draw against a `RecordingRenderer2D`) on the active document
or a named layout to confirm it lays out without error and produces the expected
structure. Cover new command/catalog logic with tests in `tests/Editor/` (mirroring
`src/`), and run Pint for style. A layout that fails to bind/lay out in the game is
the editor's responsibility to catch here.

## Conventions

- PHP 8.2+, strict types, PSR-4. 4-space indent, LF. Editor-core tests in
  `tests/Editor/`. TypeScript strict; bridge all backend calls through
  `resources/js/bridge/`.
- New editor op = command class (`src/Command/`) + `EditorServiceProvider`
  registration + typed bridge function.

## Pre-commit checklist

- [ ] No literal user-facing text / decorative-of-data colour / logic baked into a
      layout — bindings/events only.
- [ ] Repeated content uses `$each`; interactive rows carry a domain id; actions
      are wired via `$on`.
- [ ] No dead clickable buttons — non-actionable buttons gated on `visible`+`enabled`.
- [ ] Two-way control value properties are bound so the game can read them back.
- [ ] `RenderUiLayoutCommand` succeeds; `tests/Editor/` green; Pint clean.
