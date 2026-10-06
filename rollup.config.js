import babel from '@rollup/plugin-babel';
import commonjs from '@rollup/plugin-commonjs';
import resolve from '@rollup/plugin-node-resolve';
import path from 'path';

export default {
    input: 'js/src/index.js',
    output: [
        {
            file: 'js/lib/index.js',
            format: 'cjs',
        },
        {
            file: 'js/es/index.js',
            format: 'es',
        },
    ],
    plugins: [
        resolve({
            extensions: ['.mjs', '.js', '.json', '.node'],
            jail: path.join(process.cwd(), 'js/src'),
        }),
        commonjs(),
        babel({
            babelHelpers: 'bundled',
            exclude: 'node_modules/**',
        }),
    ],
};
