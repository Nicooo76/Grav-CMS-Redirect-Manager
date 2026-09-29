/** The five Lucide icons the panel draws, as path data (the icon component library costs 10 KB for them). Circles are added in the markup. */
export type IconName = 'x' | 'triangle-alert' | 'arrow-right' | 'circle-alert' | 'circle-check';

export const ICONS: Record<IconName, string[]> = {
  x: ['M18 6 6 18', 'm6 6 12 12'],
  'triangle-alert': ['m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3', 'M12 9v4', 'M12 17h.01'],
  'arrow-right': ['M5 12h14', 'm12 5 7 7-7 7'],
  'circle-alert': ['M12 8v4', 'M12 16h.01'],
  'circle-check': ['m9 12 2 2 4-4'],
};
