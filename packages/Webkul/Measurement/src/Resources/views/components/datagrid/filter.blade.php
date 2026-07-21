@pushOnce('scripts')
    <script type="text/x-template" id="v-measurement-filter-template">
        <div>
            <div class="mb-2 mt-1.5 grid grid-cols-2 gap-2">
                <input
                    type="number"
                    step="any"
                    class="block w-full rounded-md border dark:border-cherry-800 bg-white dark:bg-cherry-800 px-2 py-1.5 text-sm leading-6 text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:border-gray-600 dark:hover:border-gray-400 focus:border-gray-400 dark:focus:border-gray-400"
                    :placeholder="column.label"
                    v-model="amount"
                    @keyup.enter="apply"
                    @change="apply"
                />

                <x-admin::dropdown>
                    <x-slot:toggle>
                        <button
                            type="button"
                            class="inline-flex w-full cursor-pointer appearance-none items-center justify-between gap-x-2 rounded-md border dark:border-cherry-800 bg-white dark:bg-cherry-800 px-2.5 py-1.5 text-center leading-6 text-gray-600 dark:text-gray-300 transition-all marker:shadow hover:border-gray-400 dark:border-gray-600 dark:hover:border-gray-400 focus:border-gray-400 dark:focus:border-gray-400"
                        >
                            <span
                                class="text-sm"
                                :class="selectedUnit ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400 dark:text-gray-400'"
                                v-text="unitLabel"
                            ></span>

                            <span class="icon-chevron-down text-2xl"></span>
                        </button>
                    </x-slot>

                    <x-slot:menu>
                        <x-admin::dropdown.menu.item
                            v-for="option in column.options"
                            v-text="option.label"
                            @click="selectUnit(option.value)"
                        ></x-admin::dropdown.menu.item>
                    </x-slot>
                </x-admin::dropdown>
            </div>

            <div class="mb-4 flex flex-wrap gap-2">
                <p
                    class="flex items-center rounded bg-violet-100 px-2 py-1 font-semibold text-violet-700"
                    v-for="value in appliedValues"
                >
                    <span v-text="displayValue(value)"></span>

                    <span
                        class="icon-cancel cursor-pointer text-lg text-violet-700 ltr:ml-1.5 rtl:mr-1.5 dark:!text-violet-700"
                        @click="$emit('remove', value)"
                    ></span>
                </p>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-measurement-filter', {
            template: '#v-measurement-filter-template',

            props: {
                column: {
                    type: Object,
                    required: true,
                },

                appliedValues: {
                    type: Array,
                    default: () => [],
                },
            },

            data() {
                return {
                    amount: '',
                    selectedUnit: null,
                };
            },

            computed: {
                unitLabel() {
                    if (! this.selectedUnit) {
                        return "@lang('admin::app.components.datagrid.filters.select')";
                    }

                    let option = (this.column.options || []).find(option => option.value === this.selectedUnit);

                    return option ? option.label : this.selectedUnit;
                },
            },

            methods: {
                selectUnit(value) {
                    this.selectedUnit = value;

                    this.apply();
                },

                apply() {
                    if (this.amount === '' || this.amount === null || ! this.selectedUnit) {
                        return;
                    }

                    this.$emit('apply', [this.selectedUnit, String(this.amount)]);

                    this.amount = '';
                    this.selectedUnit = null;
                },

                displayValue(value) {
                    let option = (this.column.options || []).find(option => option.value === value[0]);

                    let unit = option ? option.label : value[0];

                    return unit + ' - ' + value[1];
                },
            },
        });
    </script>
@endPushOnce
