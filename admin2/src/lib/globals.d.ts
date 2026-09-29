export {};

declare global {
  interface Window {
    __GRAV_API_SERVER_URL?: string;
    __GRAV_API_PREFIX?: string;
    __GRAV_API_TOKEN?: string | null;
    __GRAV_ENVIRONMENT?: string;
    __GRAV_ADMIN_BASE?: string;
    __GRAV_PAGE_TAG?: string;
    __GRAV_WIDGET_TAG?: string;
    __GRAV_PANEL_TAG?: string;
    /** Page editor context (only set while a page is open there). */
    __GRAV_PAGE_ROUTE?: string;
    __GRAV_CONTENT_LANG?: string;
    __GRAV_NAVIGATE?: (url: string, opts?: Record<string, unknown>) => void;
    __GRAV_I18N?: {
      t(key: string, params?: Record<string, unknown>): string;
      tHtml?(key: string, params?: Record<string, unknown>): string;
      has(key: string): boolean;
      readonly locale: string;
      readonly dir: 'ltr' | 'rtl';
      subscribe(fn: (locale: string) => void): () => void;
    };
    __GRAV_TOAST?: {
      success(message: string, options?: Record<string, unknown>): void;
      error(message: string, options?: Record<string, unknown>): void;
      info(message: string, options?: Record<string, unknown>): void;
      warning(message: string, options?: Record<string, unknown>): void;
    };
    __GRAV_DIALOGS?: {
      confirm(options: {
        title?: string;
        message: string;
        confirmLabel?: string;
        cancelLabel?: string;
        variant?: 'destructive' | 'default';
      }): Promise<boolean>;
      form(options: {
        title?: string;
        description?: string;
        fields: Array<{
          name: string;
          type?: 'text' | 'textarea' | 'select' | 'toggle' | 'number';
          label?: string;
          placeholder?: string;
          help?: string;
          required?: boolean;
          value?: string | number | boolean;
          options?: Array<{ value: string; label: string }>;
        }>;
        submitLabel?: string;
        cancelLabel?: string;
        size?: 'sm' | 'md' | 'lg' | 'xl';
      }): Promise<Record<string, unknown> | null>;
    };
  }
}
