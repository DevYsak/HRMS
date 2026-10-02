import { execFileSync } from 'node:child_process';

const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

/**
 * Refuses to run against anything but a local app, then (re)creates the
 * fictional demo employee the screenshots are taken from.
 */
export default async function globalSetup(config) {
    const baseURL = config.projects[0].use.baseURL;
    const host = new URL(baseURL).hostname;

    if (!LOCAL_HOSTS.includes(host) && !host.endsWith('.test') && process.env.GUIDE_ALLOW_HOST !== host) {
        throw new Error(
            `Employee Guide capture refused: ${baseURL} is not a local app. ` +
            'Screenshots must be taken from a local/dev environment, never production. ' +
            `If ${host} is a disposable staging copy, set GUIDE_ALLOW_HOST=${host}.`,
        );
    }

    try {
        const response = await fetch(new URL('/login', baseURL));
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
    } catch (error) {
        throw new Error(`Cannot reach ${baseURL} (${error.message}). Start the app first, e.g. \`php artisan serve\`.`);
    }

    if (process.env.GUIDE_SKIP_SEED === '1') {
        return;
    }

    // Local/testing only: the command itself also refuses any other environment.
    const args = ['artisan', 'guide:demo-employee'];
    if (process.env.GUIDE_EMPLOYEE_PASSWORD) {
        args.push(`--password=${process.env.GUIDE_EMPLOYEE_PASSWORD}`);
    }
    execFileSync('php', args, { stdio: 'inherit' });
}
