const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = [
    // Frontend (checkout modal)
    {
        entry: './assets/js/split-payment-modal.js',
        output: {
            filename: 'split-payment-modal.min.js',
            path: path.resolve(__dirname, 'assets/js'),
        },
        plugins: [
            new MiniCssExtractPlugin({ filename: '../css/split-payment-modal.min.css' }),
        ],
        module: {
            rules: [
                {
                    test: /\.css$/,
                    use: [MiniCssExtractPlugin.loader, 'css-loader'],
                },
            ],
        },
    },
    // Admin panel
    {
        entry: './admin/assets/js/admin-settings.js',
        output: {
            filename: 'admin-settings.min.js',
            path: path.resolve(__dirname, 'admin/assets/js'),
        },
        plugins: [
            new MiniCssExtractPlugin({ filename: '../css/admin-settings.min.css' }),
        ],
        module: {
            rules: [
                {
                    test: /\.css$/,
                    use: [MiniCssExtractPlugin.loader, 'css-loader'],
                },
            ],
        },
    },
];
