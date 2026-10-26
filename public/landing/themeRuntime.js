// Canonical theme runtime. scripts/sync-platform-theme.cjs distributes it to each app.
export const validThemeColor = value => /^#[0-9a-f]{6}$/i.test(value || '');
export function mixTheme(a, b, amount) {
  return `#${[1, 3, 5].map(offset => Math.round(parseInt(a.slice(offset, offset + 2), 16) * (1 - amount) + parseInt(b.slice(offset, offset + 2), 16) * amount + 1e-9).toString(16).padStart(2, '0')).join('')}`;
}
const luminance = hex => [1, 3, 5].map(offset => parseInt(hex.slice(offset, offset + 2), 16) / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
export function themeContrast(a, b) { const x = luminance(a), y = luminance(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
function readable(color, background, ratio = 4.5) {
  const target = themeContrast('#ffffff', background) > themeContrast('#000000', background) ? '#ffffff' : '#000000';
  for (let i = 0; i <= 100; i++) { const value = mixTheme(color, target, i / 100); if (themeContrast(value, background) >= ratio) return value; }
  return target;
}
export function makeThemePalettes(primary = '#0e684e', suggestions = {}) {
  if (!validThemeColor(primary)) primary = '#0e684e';
  primary = primary.toLowerCase();
  const seeds = { secondary: '#6356a5', neutral: mixTheme(primary, '#687078', .85), success: '#168252', warning: '#bd790e', danger: '#c23a4b', info: '#3577b8', ...suggestions };
  const palettes = {};
  for (const mode of ['light', 'dark']) {
    const dark = mode === 'dark', neutral = seeds.neutral;
    const surface = mixTheme(neutral, dark ? '#080b10' : '#ffffff', dark ? .78 : .985);
    const text = readable(mixTheme(neutral, dark ? '#ffffff' : '#000000', .85), surface, 7);
    const sidebar = mixTheme(primary, '#090e16', .72);
    const onPrimary = themeContrast('#ffffff', primary) >= themeContrast('#000000', primary) ? '#ffffff' : '#000000';
    const p = {
      primary, on_primary: onPrimary, primary_hover: mixTheme(primary, onPrimary === '#ffffff' ? '#000000' : '#ffffff', .16), primary_soft: mixTheme(primary, surface, dark ? .78 : .90),
      background: mixTheme(neutral, dark ? '#05080c' : '#ffffff', dark ? .87 : .94), surface,
      surface_raised: mixTheme(neutral, dark ? '#11161e' : '#ffffff', dark ? .67 : .97), surface_muted: mixTheme(neutral, dark ? '#10151c' : '#ffffff', dark ? .55 : .90),
      text, text_muted: readable(mixTheme(text, surface, .30), surface, 4.8), text_subtle: readable(mixTheme(text, surface, .37), surface),
      border: mixTheme(neutral, surface, dark ? .35 : .70), border_strong: mixTheme(neutral, surface, dark ? .15 : .38), focus: readable(primary, surface, 3),
      sidebar, sidebar_text: readable('#f8fafc', sidebar, 7), sidebar_muted: readable(mixTheme(primary, '#ffffff', .65), sidebar, 4.5), sidebar_selected: mixTheme(primary, '#ffffff', .86),
      inverse_surface: mixTheme(neutral, '#000000', .8), inverse_text: '#ffffff', shadow: mixTheme(neutral, '#000000', .8), overlay: '#000000',
      document_paper: '#ffffff', document_ink: '#111111',
      disabled: mixTheme(neutral, surface, .78), disabled_text: readable(mixTheme(text, surface, .4), surface),
    };
    p.primary_text = readable(primary, p.primary_soft); p.sidebar_selected_text = readable(primary, p.sidebar_selected);
    for (const role of ['secondary', 'success', 'warning', 'danger', 'info']) {
      p[role] = readable(seeds[role], surface, 3); p[`${role}_soft`] = mixTheme(seeds[role], surface, dark ? .82 : .91);
      p[`${role}_text`] = readable(seeds[role], p[`${role}_soft`]);
      p[`on_${role}`] = themeContrast('#ffffff', p[role]) >= themeContrast('#000000', p[role]) ? '#ffffff' : '#000000';
    }
    p.link = readable(primary, surface);
    [primary, seeds.secondary, seeds.info, seeds.success, seeds.warning, seeds.danger].forEach((color, i) => { p[`chart_${i + 1}`] = readable(color, surface, 3); });
    palettes[mode] = p;
  }
  return palettes;
}
export const themeRoles = Object.keys(makeThemePalettes().light);
export const themeVariable = role => `--theme-${role.replaceAll('_', '-')}`;
export function themeVariables(palette) { return Object.fromEntries(themeRoles.filter(role => validThemeColor(palette?.[role])).map(role => [themeVariable(role), palette[role]])); }
export function resolvedTheme(value) {
  const fallback = makeThemePalettes(value.primary_color);
  const supplied = value.palettes;
  return Object.fromEntries(['light', 'dark'].map(mode => [mode, themeRoles.every(role => validThemeColor(supplied?.[mode]?.[role])) && supplied[mode].primary.toLowerCase() === value.primary_color?.toLowerCase() ? supplied[mode] : fallback[mode]]));
}
export function applyPlatformTheme(value) {
  if (!validThemeColor(value?.primary_color)) return;
  const mode = value.theme_mode === 'dark' ? 'dark' : 'light', palette = resolvedTheme(value)[mode];
  const root = document.documentElement;
  root.dataset.theme = mode; root.style.colorScheme = mode;
  for (const [key, color] of Object.entries(themeVariables(palette))) root.style.setProperty(key, color);
  const aliases = {
    'platform-primary': 'primary', 'platform-primary-dark': 'primary_hover', 'platform-primary-soft': 'primary_soft', 'platform-primary-light': 'secondary',
    brand: 'primary', 'brand-2': 'primary', 'brand-950': 'sidebar', 'brand-900': 'primary_hover', 'brand-800': 'primary', 'brand-700': 'primary', 'brand-100': 'primary_soft',
    accent: 'secondary_soft', 'accent-soft': 'secondary_soft', surface: 'surface', 'surface-soft': 'surface_raised', 'surface-muted': 'surface_muted', text: 'text', muted: 'text_muted', border: 'border', line: 'border', 'line-strong': 'border_strong', danger: 'danger', 'danger-bg': 'danger_soft',
    'office-text': 'text', 'office-primary': 'primary', 'office-border': 'border', 'office-background': 'background', 'office-brand': 'primary', 'office-brand-hover': 'primary_hover', 'office-accent': 'secondary_soft', 'office-bg': 'background', 'office-panel': 'surface', 'office-line': 'border', 'office-line-soft': 'surface_muted', 'office-ink': 'text', 'office-muted': 'text_muted',
  };
  for (const [key, role] of Object.entries(aliases)) root.style.setProperty(`--${key}`, palette[role]);
}

export function themeColor(role) {
  return getComputedStyle(document.documentElement).getPropertyValue(themeVariable(role)).trim() || makeThemePalettes().light[role];
}
