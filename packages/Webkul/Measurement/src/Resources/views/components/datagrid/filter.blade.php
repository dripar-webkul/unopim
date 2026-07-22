@pushOnce('scripts')
    <script type="text/x-template" id="v-measurement-filter-template">
        <div>
            <div class="mb-2 mt-1.5">
                <x-admin::dropdown>
                    <x-slot:toggle>
                        <button
                            type="button"
                            class="inline-flex w-full cursor-pointer appearance-none items-center justify-between gap-x-2 rounded-md border dark:border-cherry-800 bg-white dark:bg-cherry-800 px-2.5 py-1.5 text-center leading-6 text-gray-600 dark:text-gray-300 transition-all marker:shadow hover:border-gray-400 dark:border-gray-600 dark:hover:border-gray-400 focus:border-gray-400 dark:focus:border-gray-400"
                        >
                            <span class="text-sm text-gray-600 dark:text-gray-300" v-text="operatorLabel"></span>

                            <span class="icon-chevron-down text-2xl"></span>
                        </button>
                    </x-slot>

                    <x-slot:menu>
                        <x-admin::dropdown.menu.item
                            v-for="option in operatorOptions"
                            v-text="option.label"
                            @click="selectOperator(option.value)"
                        ></x-admin::dropdown.menu.item>
                    </x-slot>
                </x-admin::dropdown>
            </div>

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

            <div class="mb-2" v-if="isRange">
                <input
                    type="number"
                    step="any"
                    class="block w-full rounded-md border dark:border-cherry-800 bg-white dark:bg-cherry-800 px-2 py-1.5 text-sm leading-6 text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:border-gray-600 dark:hover:border-gray-400 focus:border-gray-400 dark:focus:border-gray-400"
                    :placeholder="'@lang('measurement::app.filter.to')'"
                    v-model="amountTo"
                    @keyup.enter="apply"
                    @change="apply"
                />
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
                    amountTo: '',
                    selectedUnit: null,
                    operator: 'eq',
                    operatorOptions: [
                        { value: 'eq',            label: "@lang('measurement::app.filter.operators.eq')" },
                        { value: 'neq',           label: "@lang('measurement::app.filter.operators.neq')" },
                        { value: 'gt',            label: "@lang('measurement::app.filter.operators.gt')" },
                        { value: 'gte',           label: "@lang('measurement::app.filter.operators.gte')" },
                        { value: 'lt',            label: "@lang('measurement::app.filter.operators.lt')" },
                        { value: 'lte',           label: "@lang('measurement::app.filter.operators.lte')" },
                        { value: 'within_range',  label: "@lang('measurement::app.filter.operators.within_range')" },
                        { value: 'outside_range', label: "@lang('measurement::app.filter.operators.outside_range')" },
                        { value: 'in_list',       label: "@lang('measurement::app.filter.operators.in_list')" },
                        { value: 'not_in_list',   label: "@lang('measurement::app.filter.operators.not_in_list')" },
                    ],
                };
            },

            computed: {
                isRange() {
                    return this.operator === 'within_range' || this.operator === 'outside_range';
                },

                operatorLabel() {
                    let option = this.operatorOptions.find(option => option.value === this.operator);

                    return option ? option.label : this.operator;
                },

                unitLabel() {
                    if (! this.selectedUnit) {
                        return "@lang('admin::app.components.datagrid.filters.select')";
                    }

                    let option = (this.column.options || []).find(option => option.value === this.selectedUnit);

                    return option ? option.label : this.selectedUnit;
                },
            },

            methods: {
                selectOperator(value) {
                    this.operator = value;
                },

                selectUnit(value) {
                    this.selectedUnit = value;

                    this.apply();
                },

                apply() {
                    if (this.amount === '' || this.amount === null || ! this.selectedUnit) {
                        return;
                    }

                    if (this.isRange && (this.amountTo === '' || this.amountTo === null)) {
                        return;
                    }

                    let applied = [this.operator, this.selectedUnit, String(this.amount)];

                    if (this.isRange) {
                        applied.push(String(this.amountTo));
                    }

                    this.$emit('apply', applied);

                    this.amount = '';
                    this.amountTo = '';
                    this.selectedUnit = null;
                },

                displayValue(value) {
                    let offset = this.operatorOptions.some(option => option.value === value[0]) ? 1 : 0;

                    let operator = offset
                        ? (this.operatorOptions.find(option => option.value === value[0]) || {}).label
                        : '';

                    let unitOption = (this.column.options || []).find(option => option.value === value[offset]);

                    let unit = unitOption ? unitOption.label : value[offset];

                    let amounts = value.slice(offset + 1).join(' - ');

                    return (operator ? operator + ' ' : '') + unit + ' - ' + amounts;
                },
            },
        });
    </script>
@endPushOnce
