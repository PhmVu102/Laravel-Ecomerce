    <?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatHistoryController extends Controller
{
    public function index(Request $request)
    {
        $conversations = $request->user()
            ->conversations()
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($conversations);
    }

    public function storeConversation(Request $request)
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $conversation = Conversation::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'] ?? null,
        ]);

        return response()->json($conversation, 201);
    }

    public function show(Request $request, Conversation $conversation)
    {
        // ensure conversation belongs to the authenticated user
        if ($conversation->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return response()->json($messages);
    }

    public function storeMessage(Request $request, Conversation $conversation)
    {
        if ($conversation->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'content' => 'required|string',
            'role' => 'nullable|string|in:user,assistant,system',
            'meta' => 'nullable|array',
        ]);

        $role = $data['role'] ?? 'user';

        // If message is from assistant or system, store without attaching the authenticated user's id
        $messageUserId = in_array($role, ['assistant', 'system']) ? null : $request->user()->id;

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $messageUserId,
            'role' => $role,
            'content' => $data['content'],
            'meta' => $data['meta'] ?? null,
        ]);

        // touch conversation updated_at so ordering works
        $conversation->touch();

        // If user confirmed (e.g., replied "đồng ý"), prefer using assistant.meta.draft_order to create the order
        $normalized = mb_strtolower(trim($data['content']));

        if (in_array($normalized, ['đồng ý', 'dong y', 'đồng y', 'ok', 'xác nhận', 'xac nhan', 'confirm'])) {
            // find last assistant message that carries a structured draft in meta
            $assistant = $conversation->messages()
                ->where('role', 'assistant')
                ->whereNotNull('meta')
                ->orderByDesc('created_at')
                ->get()
                ->first();

            $draft = null;

            if ($assistant && is_array($assistant->meta) && array_key_exists('draft_order', $assistant->meta)) {
                $draft = $assistant->meta['draft_order'];
            }

            // fallback to parsing text if meta not present
            if (!$draft && $assistant) {
                if (preg_match('/Tổng thanh toán|Sản phẩm|Người nhận|Phương thức thanh toán/i', $assistant->content)) {
                    $draft = $this->parseOrderDraftFromAssistant($assistant->content);
                }
            }

            if ($draft) {
                try {
                    // If draft contains structured variant/product ids, use the more reliable creator
                    if (isset($draft['items']) && is_array($draft['items']) && count($draft['items']) > 0 && (isset($draft['items'][0]['variant_id']) || isset($draft['items'][0]['product_id']))) {
                        $order = $this->createOrderFromMeta($draft, $request->user());
                    } else {
                        // fallback to older method
                        $order = $this->createOrderFromDraft($draft, $request->user());
                    }

                    // Save assistant-confirmation message into conversation
                    Message::create([
                        'conversation_id' => $conversation->id,
                        'user_id' => null,
                        'role' => 'system',
                        'content' => "Đơn hàng đã được tạo: #{$order->id} (Mã: {$order->order_code}).",
                        'meta' => ['order_id' => $order->id],
                    ]);

                    return response()->json(['message' => 'Order created', 'order_id' => $order->id], 201);

                } catch (\Exception $e) {
                    // Save assistant/system message describing failure
                    Message::create([
                        'conversation_id' => $conversation->id,
                        'user_id' => null,
                        'role' => 'system',
                        'content' => 'Không thể tạo đơn hàng tự động: ' . $e->getMessage(),
                        'meta' => null,
                    ]);

                    return response()->json(['message' => 'Failed to create order', 'error' => $e->getMessage()], 400);
                }
            }
        }

        return response()->json($message, 201);
    }

    /**
     * Try to parse an assistant order summary into structured draft data.
     * Returns null on failure or array with keys: items (array), shipping_name, shipping_phone, shipping_address, payment_method, total
     */
    private function parseOrderDraftFromAssistant(string $content): ?array
    {
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $content);

        // Attempt to extract fields
        $getLine = function($label) use ($text) {
            if (preg_match('/'.preg_quote($label, '/')."[:**\s]*([^\n]+)/iu", $text, $m)) {
                return trim($m[1]);
            }

            // try without markdown bold
            if (preg_match('/'.preg_quote($label, '/')."[:]\s*([^\n]+)/iu", $text, $m2)) {
                return trim($m2[1]);
            }

            return null;
        };

        $productLine = $getLine('Sản phẩm');
        $totalLine = $getLine('Tổng thanh toán');
        $recipientLine = $getLine('Người nhận');
        $addressLine = $getLine('Địa chỉ');
        $paymentLine = $getLine('Phương thức thanh toán');

        if (!$productLine || !$recipientLine || !$addressLine) {
            return null;
        }

        // parse product: may contain quantity at start
        $items = [];
        // productLine may list multiple products separated by ";" or "," or newline. Try split by \n or ;
        $parts = preg_split('/[;\n]+/', $productLine);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            // match leading quantity like "02" or "2" or "2 x"
            if (preg_match('/^(\d+)\s*[x×*]?\s*(.+)$/u', $part, $mm)) {
                $qty = intval($mm[1]);
                $name = trim($mm[2]);
            } elseif (preg_match('/^(\d{1,3})(?:\.|,)?\s+(.+)$/u', $part, $mm2)) {
                // sometimes prefixed with 02 without x
                $qty = intval($mm2[1]);
                $name = trim($mm2[2]);
            } else {
                // default quantity 1
                $qty = 1;
                $name = $part;
            }

            $items[] = ['name' => $name, 'quantity' => $qty];
        }

        // parse recipient and phone
        $shipping_name = $recipientLine;
        $shipping_phone = null;
        if (preg_match('/^(.*)\(([^)]+)\)\s*$/u', $recipientLine, $rm)) {
            $shipping_name = trim($rm[1]);
            $shipping_phone = trim($rm[2]);
        } elseif (preg_match('/(\+?\d[\d\s\-]{6,})/u', $recipientLine, $pm)) {
            $shipping_phone = trim($pm[1]);
            $shipping_name = trim(str_replace($shipping_phone, '', $recipientLine));
        }

        // payment method normalization
        $payment_method = null;
        if ($paymentLine) {
            $p = mb_strtolower($paymentLine);
            if (strpos($p, 'chuyển') !== false || strpos($p, 'bank') !== false || strpos($p, 'vnpay') !== false) {
                $payment_method = 'bank';
            } elseif (strpos($p, 'momo') !== false) {
                $payment_method = 'momo';
            } elseif (strpos($p, 'cod') !== false || strpos($p, 'tiền mặt') !== false) {
                $payment_method = 'cod';
            } else {
                $payment_method = 'bank';
            }
        }

        // parse total amount number
        $total = null;
        if ($totalLine && preg_match('/([\d\.,]+)\s*đ?/u', $totalLine, $tm)) {
            $total = floatval(str_replace([',','.' ], ['', ''], $tm[1]));
        }

        return [
            'items' => $items,
            'shipping_name' => $shipping_name,
            'shipping_phone' => $shipping_phone,
            'shipping_address' => $addressLine,
            'payment_method' => $payment_method,
            'total' => $total,
        ];
    }

    /**
     * Create order from parsed draft. Uses Product lookup to resolve variants.
     */
    private function createOrderFromDraft(array $draft, \App\Models\User $user)
    {
        // existing implementation left unchanged - uses name parsing
        // load necessary models
        $cartItems = [];
        $subtotal = 0;

        foreach ($draft['items'] as $it) {
            $name = $it['name'];
            $qty = $it['quantity'] ?? 1;

            // try to find product by name
            $product = \App\Models\Product::where('name', 'like', "%{$name}%")->first();

            if (!$product) {
                throw new \Exception("Không tìm thấy sản phẩm: {$name}");
            }

            // get a variant (first available)
            $variant = $product->variants()->first();

            if (!$variant) {
                throw new \Exception("Sản phẩm không có biến thể: {$product->name}");
            }

            if ($variant->stock < $qty) {
                throw new \Exception("Sản phẩm '{$product->name}' không đủ tồn kho.");
            }

            $price = $variant->sale_price ?? $variant->price;
            $subtotal += $price * $qty;

            $cartItems[] = [
                'variant' => $variant,
                'product' => $product,
                'quantity' => $qty,
                'price' => $price,
            ];
        }

        $shippingFee = 0;
        $discount = 0;
        $total = $subtotal + $shippingFee - $discount;

        // Begin transaction
        DB::beginTransaction();
        try {
            $order = \App\Models\Order::create([
                'user_id' => $user->id,
                'order_code' => 'ORD' . now()->format('YmdHis') . rand(10, 99),
                'shipping_name' => $draft['shipping_name'] ?? $user->name,
                'shipping_phone' => $draft['shipping_phone'] ?? $user->phone ?? null,
                'shipping_email' => $user->email ?? null,
                'shipping_address' => $draft['shipping_address'] ?? null,
                'customer_note' => null,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'payment_method' => $draft['payment_method'] ?? 'bank',
                'payment_status' => 'unpaid',
                'status' => 'pending',
            ]);

            foreach ($cartItems as $ci) {
                \App\Models\OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $ci['product']->id,
                    'product_variant_id' => $ci['variant']->id,
                    'product_name' => $ci['product']->name,
                    'product_image' => $ci['variant']->image ?? null,
                    'price' => $ci['price'],
                    'quantity' => $ci['quantity'],
                    'total' => $ci['price'] * $ci['quantity'],
                ]);

                // decrement stock
                $ci['variant']->decrement('stock', $ci['quantity']);
            }

            if (($draft['payment_method'] ?? 'bank') !== 'cod') {
                \App\Models\Payment::create([
                    'order_id' => $order->id,
                    'transaction_id' => null,
                    'payment_method' => $draft['payment_method'] ?? 'bank',
                    'amount' => $total,
                    'status' => 'pending',
                    'raw_response_data' => null,
                ]);
            }

            DB::commit();

            return $order;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create order using structured meta.draft_order provided by assistant.
     * Expected structure:
     *  [
     *    'items' => [ ['variant_id' => int, 'product_id' => int (optional), 'quantity' => int], ... ],
     *    'shipping_name' => string,
     *    'shipping_phone' => string,
     *    'shipping_address' => string,
     *    'payment_method' => 'cod'|'bank'|'momo'|...,
     *    'customer_note' => string (optional)
     *  ]
     */
    private function createOrderFromMeta(array $metaDraft, \App\Models\User $user)
    {
        if (empty($metaDraft['items']) || !is_array($metaDraft['items'])) {
            throw new \Exception('Draft order không có items hợp lệ.');
        }

        $cartItems = [];
        $subtotal = 0;

        foreach ($metaDraft['items'] as $it) {
            $qty = intval($it['quantity'] ?? 1);

            $variant = null;
            if (!empty($it['variant_id'])) {
                $variant = \App\Models\ProductVariant::lockForUpdate()->find($it['variant_id']);
            } elseif (!empty($it['product_id'])) {
                $product = \App\Models\Product::find($it['product_id']);
                if (!$product) {
                    throw new \Exception("Không tìm thấy product_id: {$it['product_id']}");
                }
                $variant = $product->variants()->lockForUpdate()->first();
            }

            if (!$variant) {
                throw new \Exception('Không tìm thấy biến thể sản phẩm cho 1 item trong draft.');
            }

            if ($variant->stock < $qty) {
                throw new \Exception("Sản phẩm '{$variant->product->name}' không đủ tồn kho.");
            }

            $price = $variant->sale_price ?? $variant->price;
            $subtotal += $price * $qty;

            $cartItems[] = [
                'variant' => $variant,
                'product' => $variant->product,
                'quantity' => $qty,
                'price' => $price,
            ];
        }

        $shippingFee = 0;
        $discount = 0;
        $total = $subtotal + $shippingFee - $discount;

        DB::beginTransaction();
        try {
            $order = \App\Models\Order::create([
                'user_id' => $user->id,
                'order_code' => 'ORD' . now()->format('YmdHis') . rand(10, 99),
                'shipping_name' => $metaDraft['shipping_name'] ?? $user->name,
                'shipping_phone' => $metaDraft['shipping_phone'] ?? $user->phone ?? null,
                'shipping_email' => $user->email ?? null,
                'shipping_address' => $metaDraft['shipping_address'] ?? null,
                'customer_note' => $metaDraft['customer_note'] ?? null,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'payment_method' => $metaDraft['payment_method'] ?? 'bank',
                'payment_status' => 'unpaid',
                'status' => 'pending',
            ]);

            foreach ($cartItems as $ci) {
                \App\Models\OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $ci['product']->id,
                    'product_variant_id' => $ci['variant']->id,
                    'product_name' => $ci['product']->name,
                    'product_image' => $ci['variant']->image ?? null,
                    'price' => $ci['price'],
                    'quantity' => $ci['quantity'],
                    'total' => $ci['price'] * $ci['quantity'],
                ]);

                // decrement stock
                $ci['variant']->decrement('stock', $ci['quantity']);
            }

            if (($metaDraft['payment_method'] ?? 'bank') !== 'cod') {
                \App\Models\Payment::create([
                    'order_id' => $order->id,
                    'transaction_id' => null,
                    'payment_method' => $metaDraft['payment_method'] ?? 'bank',
                    'amount' => $total,
                    'status' => 'pending',
                    'raw_response_data' => null,
                ]);
            }

            DB::commit();

            return $order;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
