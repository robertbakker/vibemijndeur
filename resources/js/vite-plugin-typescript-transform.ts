import { exec } from 'node:child_process';
import osPath from 'node:path';
import { promisify } from 'node:util';
import { minimatch } from 'minimatch';
import type { HmrContext, Plugin, ResolvedConfig } from 'vite';

const execAsync = promisify(exec);

interface TypeScriptTransformOptions {
    patterns?: string[];
    command?: string;
}

export const typescriptTransform = ({
    patterns = ['app/**/Data/**/*.php', 'app/Data/**/*.php'],
    command = 'php artisan typescript:transform',
}: TypeScriptTransformOptions = {}): Plugin => {
    patterns = patterns.map((pattern) => pattern.replaceAll('\\', '/'));

    let config: ResolvedConfig;

    const runCommand = async () => {
        try {
            await execAsync(command);
            config?.logger.info('TypeScript types generated');
        } catch (error) {
            const stderr = (error as { stderr?: string })?.stderr ?? '';
            throw new Error(
                `Error generating TypeScript types: ${error}${stderr ? `\n${stderr}` : ''}`,
            );
        }
    };

    return {
        name: 'typescript-transform',
        enforce: 'pre',
        configResolved(resolved) {
            config = resolved;
        },
        buildStart() {
            return runCommand();
        },
        async handleHotUpdate({ file, server }) {
            if (shouldRun(patterns, { file, server })) {
                await runCommand();
            }
        },
    };
};

const shouldRun = (
    patterns: string[],
    opts: Pick<HmrContext, 'file' | 'server'>,
): boolean => {
    const file = opts.file.replaceAll('\\', '/');

    return patterns.some((pattern) => {
        pattern = osPath
            .resolve(opts.server.config.root, pattern)
            .replaceAll('\\', '/');

        return minimatch(file, pattern);
    });
};
