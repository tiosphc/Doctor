@if($transactions->isEmpty())
    <x-empty-state title="Chưa có giao dịch ví phù hợp." />
@else
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[820px] text-left text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Thời gian</th>@if($showDealer ?? false)<th class="px-4 py-3">Đại lý</th>@endif<th class="px-4 py-3">Loại</th><th class="px-4 py-3">Mã / tham chiếu</th><th class="px-4 py-3 text-right">Biến động</th><th class="px-4 py-3 text-right">Số dư sau</th><th class="px-4 py-3">Thực hiện bởi</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($transactions as $transaction)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3">{{ $transaction->created_at?->format('d/m/Y H:i') }}</td>
                        @if($showDealer ?? false)<td class="px-4 py-3"><a class="font-semibold text-junie-navy underline" href="{{ route('admin.web.dealer-wallets.show', $transaction->wallet->dealerAccount) }}">{{ $transaction->wallet->dealerAccount->code }} · {{ $transaction->wallet->dealerAccount->legal_name }}</a></td>@endif
                        <td class="px-4 py-3">{{ ['deposit_credit' => 'Nạp tiền', 'order_debit' => 'Thanh toán đơn', 'refund_credit' => 'Hoàn tiền'][$transaction->type] ?? $transaction->type }}</td>
                        <td class="px-4 py-3"><span class="block break-all font-medium">{{ $transaction->transaction_code }}</span><span class="block break-all text-slate-500">{{ $transaction->deposit?->deposit_code ?? $transaction->salesOrder?->order_code ?? $transaction->refund?->refund_code ?? '—' }}</span>@if($transaction->deposit?->external_reference)<span class="block break-all text-xs text-slate-500">{{ $transaction->deposit->external_reference }}</span>@endif</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold {{ $transaction->direction === 'credit' ? 'text-emerald-700' : 'text-red-700' }}">{{ $transaction->direction === 'credit' ? '+' : '−' }}<x-formatted-number :value="$transaction->amount" currency /></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><x-formatted-number :value="$transaction->balance_after" currency /></td>
                        <td class="px-4 py-3">{{ $transaction->actor?->name ?? 'Hệ thống' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
