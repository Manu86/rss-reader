import { run } from './harness.mjs';
import './client.test.js';
import './router.test.js';
import './format.test.js';
import './reader.test.mjs';
import './pwa.test.mjs';
import './quality.test.mjs';
import './accessibility.test.mjs';

run().catch((error) => {
    process.stderr.write(`${error && error.stack ? error.stack : error}\n`);
    process.exitCode = 1;
});
