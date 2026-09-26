const tests = [];

export function test(name, callback) {
    tests.push({ name, callback });
}

export async function run() {
    let failures = 0;

    for (const entry of tests) {
        try {
            await entry.callback();
            process.stdout.write(`ok - ${entry.name}\n`);
        } catch (error) {
            failures += 1;
            process.stderr.write(`not ok - ${entry.name}\n`);
            process.stderr.write(`${error && error.stack ? error.stack : error}\n`);
        }
    }

    process.stdout.write(`\n1..${tests.length}\n`);
    if (failures > 0) {
        throw new Error(`${failures} test(s) failed`);
    }
}
