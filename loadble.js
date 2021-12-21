// This code sets a loader when calling a promise based API request from vuejs files. It's basically a mixin.
export default {
    data() {
        return {
            isLoading: false,
            loadingText: '',
            loadingTask: null,
            loaders: {}
        };
    },
    methods: {
        setLoader(loaderName = null, { isLoading, loadingText, loadingTask }) {
            let loader =
                loaderName === null
                    ? this
                    : this.loaders[loaderName] ||
                      (this.loaders[loaderName] = {});

            loader.isLoading = isLoading || false;
            loader.loadingText = loadingText || 'Loading';
            loader.loadingTask = loadingTask || null;
            return loader;
        },

        resetLoader(loaderName = null) {
            let loader = loaderName === null ? this : this.loaders[loaderName];

            if (!loader) {
                throw new Error('Loader does not exist!');
            }

            loader.isLoading = false;
            loader.loadingText = '';
            loader.loadingTask = null;
            return loader;
        },

        gonnaBe(loadingText = 'Loading') {
            let loaderName = null;

            let instance = {
                as: customLoaderName => {
                    loaderName = customLoaderName;
                },
                with: (...tasks) => {
                    let loadingTask = Promise.all(tasks).finally(() => {
                        this.resetLoader(loaderName);
                    });

                    this.setLoader(loaderName, {
                        isLoading: true,
                        loadingText,
                        loadingTask
                    });

                    return loadingTask;
                }
            };

            return instance;
        }
    }
};
