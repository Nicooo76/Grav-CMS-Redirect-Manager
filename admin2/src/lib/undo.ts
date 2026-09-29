/**
 * Holds deleted items for a short grace period so they can be restored.
 * The clock is injectable for tests.
 */
export interface UndoEntry<T> {
  id: number;
  items: T[];
  expiresAt: number;
}

export class UndoBuffer<T> {
  #entries: UndoEntry<T>[] = [];
  #seq = 0;

  constructor(
    private readonly ttlMs = 10_000,
    private readonly now: () => number = () => Date.now(),
  ) {}

  push(items: T[]): UndoEntry<T> {
    this.sweep();
    const entry = { id: ++this.#seq, items, expiresAt: this.now() + this.ttlMs };
    this.#entries.push(entry);
    return entry;
  }

  /** Removes and returns the entry if it has not expired. */
  take(id: number): UndoEntry<T> | null {
    this.sweep();
    const i = this.#entries.findIndex((e) => e.id === id);
    if (i === -1) return null;
    return this.#entries.splice(i, 1)[0];
  }

  /** Most recent live entry (for the Ctrl+Z shortcut). */
  latest(): UndoEntry<T> | null {
    this.sweep();
    return this.#entries.length ? this.#entries[this.#entries.length - 1] : null;
  }

  sweep(): void {
    const t = this.now();
    this.#entries = this.#entries.filter((e) => e.expiresAt > t);
  }

  get size(): number {
    this.sweep();
    return this.#entries.length;
  }
}
