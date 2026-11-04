import { writeSync } from 'node:fs';

export default class ProgressReporter {
  onBegin(config, suite) {
    this.total = suite.allTests().length;
    this.current = 0;
  }

  onTestBegin(test) {
    this.current += 1;
    writeSync(1, `[${this.current}/${this.total}] ${test.title}\n`);
  }
}
