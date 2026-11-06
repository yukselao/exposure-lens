import { createWriteStream, type WriteStream } from 'node:fs';

type LogFields = Record<string, string | number | boolean | null | undefined>;

export class ConnectorLogger {
  private file?: WriteStream;

  constructor(path?: string) {
    if (!path) return;
    this.file = createWriteStream(path, { flags: 'a', mode: 0o600 });
    this.file.on('error', (error: NodeJS.ErrnoException) => {
      this.file = undefined;
      this.error('log_file_unavailable', { code: error.code });
    });
  }

  info(event: string, fields: LogFields = {}): void {
    this.write('info', event, fields);
  }

  error(event: string, fields: LogFields = {}): void {
    this.write('error', event, fields);
  }

  private write(level: 'info' | 'error', event: string, fields: LogFields): void {
    const line = JSON.stringify({ timestamp: new Date().toISOString(), level, event, ...fields }) + '\n';
    process.stdout.write(line);
    this.file?.write(line);
  }
}
