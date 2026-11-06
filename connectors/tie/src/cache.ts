import type { ADObjectStore } from './ad-object-store.js';
import { scanFreshness } from './freshness.js';

type CacheState = 'warming' | 'refreshing' | 'ready' | 'failed';
type CachePhase = 'waiting' | 'scanning' | 'consolidating' | 'complete' | 'failed';
type ScanTrigger = 'startup' | 'manual' | 'scheduled' | 'expiry';

export class CacheLifecycle {
  private snapshot: ADObjectStore | null = null;
  private state: CacheState = 'warming';
  private phase: CachePhase = 'waiting';
  private trigger: ScanTrigger = 'startup';
  private pagesFetched = 0;
  private objectsFetched = 0;
  private startedAt = 0;
  private finishedAt: number | null = null;
  private objectCount: number | null = null;
  private timer: NodeJS.Timeout | undefined;
  private nextRefreshAt: string | null = null;

  constructor(
    private readonly createStore: () => ADObjectStore,
    private readonly publish: (store: ADObjectStore) => void,
    private readonly refreshIntervalMs: number,
  ) {}

  get refreshInProgress() {
    return this.state === 'warming' || this.state === 'refreshing';
  }

  private snapshotFreshness(snapshot = this.snapshot) {
    if (!snapshot) return null;
    const { scanStartedAt, builtAt, ttlMs } = snapshot.stats();
    return scanFreshness('ad_snapshot', scanStartedAt, builtAt, ttlMs);
  }

  status() {
    return {
      state: this.state,
      phase: this.phase,
      trigger: this.trigger,
      pagesFetched: this.pagesFetched,
      objectsFetched: this.objectsFetched,
      elapsedMs: this.startedAt ? (this.finishedAt ?? Date.now()) - this.startedAt : 0,
      objectCount: this.objectCount,
      nextRefreshAt: this.nextRefreshAt,
      freshness: { adSnapshot: this.snapshotFreshness(), refreshInProgress: this.refreshInProgress },
      ...(this.state === 'failed' && { error: 'AD scan failed. Call refresh_ad_cache to retry immediately.' }),
    };
  }

  ready(): boolean {
    if (this.state === 'ready' && !this.refreshIntervalMs && !this.snapshot?.stats().fresh) {
      this.start('expiry');
    }
    return this.state === 'ready' || (this.trigger === 'scheduled' && this.snapshot !== null);
  }

  start(trigger: ScanTrigger = 'manual'): ReturnType<CacheLifecycle['status']> {
    if (this.startedAt && this.finishedAt === null) return this.status();

    clearTimeout(this.timer);
    this.nextRefreshAt = null;
    this.trigger = trigger;
    this.state = this.objectCount === null ? 'warming' : 'refreshing';
    this.phase = 'scanning';
    this.pagesFetched = 0;
    this.objectsFetched = 0;
    this.startedAt = Date.now();
    this.finishedAt = null;
    void this.scan();
    return this.status();
  }

  async run<T>(operation: () => Promise<T>) {
    const snapshot = this.snapshot;
    const result = await operation();
    return { result, adSnapshot: this.snapshotFreshness(snapshot) };
  }

  private async scan(): Promise<void> {
    try {
      // Existing requests keep their old store until they finish.
      const store = this.createStore();
      await store.ensureLoaded(true, ({ pages, objects }) => {
        this.pagesFetched = pages;
        this.objectsFetched = objects;
      });
      this.phase = 'consolidating';
      this.objectCount = store.ensureConsolidated().count;
      this.publish(store);
      this.snapshot = store;
      this.phase = 'complete';
      this.state = 'ready';
    } catch (error) {
      console.error('AD snapshot scan failed:', error);
      this.phase = 'failed';
      this.state = 'failed';
    } finally {
      this.finishedAt = Date.now();
      if (this.refreshIntervalMs) {
        this.nextRefreshAt = new Date(this.finishedAt + this.refreshIntervalMs).toISOString();
        this.timer = setTimeout(() => this.start('scheduled'), this.refreshIntervalMs).unref();
      }
    }
  }
}
