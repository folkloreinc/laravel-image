// Shared by Rollup (build) and Jest (tests).
module.exports = (api) => {
    const isTest = api.env('test');

    return {
        presets: [
            [
                '@babel/preset-env',
                isTest ? { targets: { node: 'current' } } : { modules: false },
            ],
        ],
    };
};
