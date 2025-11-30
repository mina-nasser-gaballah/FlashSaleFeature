# Flash Sale Checkout API

High-concurrency flash sale system built with Laravel 12, handling burst traffic without overselling, with temporary holds, order creation, and idempotent payment webhooks.

## Assumptions and Invariants Enforced

### Assumptions
1. **Hold Duration**: Holds expire after 2 minutes and automatically release stock via background job
2. **Stock Model**: `stock` field represents available stock directly. Decremented on hold creation, incremented on expiry/payment failure
3. **Hold Lifecycle**: States: `active`, `expired`, `converted`, `cancelled`
4. **Order Lifecycle**: States: `pending`, `paid`, `cancelled`
5. **Webhook Idempotency**: Payment webhooks use idempotency keys to prevent duplicate processing
6. **Out-of-Order Webhooks**: Webhook arriving before order exists returns 404, expects retry
7. **Cache Strategy**: Product details (id, name, price_cents) are cached, but stock is always fetched fresh
8. **Order Cache**: Orders are cached by hold_id to prevent duplicate order creation attempts

### Invariants Enforced
1. **No Overselling**: Database transactions with row-level locking (`lockForUpdate()`) and atomic stock updates (`WHERE stock >= quantity`) ensure stock is never oversold, even under high concurrency
2. **Hold Uniqueness**: Each hold can only be converted to one order (unique constraint on `hold_id` in database)
3. **Payment Idempotency**: Payment webhooks with the same idempotency key are processed only once, preventing duplicate payments
4. **Idempotency Key Validation**: Idempotency keys must match the order_id - same key cannot be used for different orders (returns 409 Conflict)
5. **Single Successful Payment**: An order can only have one successful payment - subsequent payment attempts are rejected even with different idempotency keys
6. **Paid Order Protection**: Orders with status `'paid'` cannot receive new payment webhooks (returns 409 Conflict)
7. **Payment Retry Support**: Cancelled orders can be paid again - successful payment after cancellation updates status to `'paid'` and restores hold status
8. **Stock Accuracy**: `stock` field always fetched fresh from database, never cached to ensure real-time availability
9. **Hold Expiry**: Expired holds automatically processed by `ProcessExpiredHolds` job every minute via Laravel scheduler
10. **Cache Consistency**: Order cache is updated when order status changes to keep cache in sync with database
11. **Transaction Safety**: All critical operations (hold creation, order creation, payment processing) use database transactions with retry logic for deadlock handling

## How to Run the App

### Prerequisites
PHP 8.2+, MySQL 8.0+, Composer, Laravel 12

### Setup
```bash

composer install
cp .env.example .env
php artisan key:generate
```

Update `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flash_sale
DB_USERNAME=root
DB_PASSWORD=your_password
CACHE_DRIVER=database
QUEUE_CONNECTION=database
```

```bash
php artisan migrate
php artisan db:seed
```

### Start Services
```bash
php artisan queue:work

php artisan schedule:work

php artisan serve
```

API: `http://localhost:8000/api`

## How to Run Tests

```bash
php artisan test
```

### Automated Tests

The test suite includes 4 critical tests that verify system behavior under edge cases:

**1. Parallel Hold Attempts at Stock Boundary (No Oversell)** - `ParallelHoldTest`:
- `parallel_hold_attempts_at_stock_boundary_no_oversell()` - Simulates 15 concurrent requests for stock of 10; verifies exactly 10 succeed and 5 fail, preventing overselling

**2. Hold Expiry Returns Availability** - `HoldExpiryTest`:
- `hold_expiry_returns_availability()` - Creates hold, expires it, processes expiry job, and verifies stock is returned and available for new holds

**3. Webhook Idempotency (Same Key Repeated)** - `WebhookIdempotencyTest`:
- `webhook_idempotency_same_key_repeated()` - Sends same idempotency key twice; verifies second call returns "already processed" without creating duplicate payment records

**4. Webhook Arriving Before Order Creation** - `WebhookBeforeOrderTest`:
- `webhook_arriving_before_order_creation()` - Sends webhook for non-existent order (returns 404), then creates order and retries webhook (succeeds)

## Where to See Logs/Metrics

### Logs Location
```
storage/logs/flash_sale_feature.log
```

### Viewing Logs

**Real-time log monitoring:**
```bash
tail -f storage/logs/flash_sale_feature.log
```

**Windows PowerShell:**
```powershell
Get-Content storage/logs/flash_sale_feature.log -Wait -Tail 50
```

**View specific log entries:**
```bash
grep "Hold created" storage/logs/flash_sale_feature.log
grep "deadlock_contention" storage/logs/flash_sale_feature.log
grep "webhook_deduplication" storage/logs/flash_sale_feature.log
```

### Key Log Events

**Hold Operations:**
- `Hold created` - Successful hold creation with hold_id, product_id, quantity, expires_at
- `Insufficient stock for hold` - Warning when stock is not available
- `Hold creation contention detected (deadlock/retry)` - Deadlock detected during hold creation
- `Hold creation failed` - General error during hold creation

**Order Operations:**
- `Order created from hold` - Successful order creation with order_id, hold_id, product_id, quantity, total_price_cents
- `Order retrieved from cache` - Order found in cache (preventing duplicate creation)
- `Order creation contention detected (deadlock/retry)` - Deadlock detected during order creation
- `Order creation failed` - General error during order creation

**Payment Operations:**
- `Order marked as paid` - Payment webhook successfully processed for pending order
- `Order marked as paid after previous cancellation` - Payment succeeded after previous failure, order restored from cancelled to paid
- `Duplicate webhook detected (idempotency)` - Webhook with duplicate idempotency key (metric_type: webhook_deduplication)
- `Idempotency key mismatch detected` - Same idempotency key used for different order_id (metric_type: idempotency_key_mismatch)
- `Payment webhook received for already paid order` - Attempt to pay an order that's already paid
- `Payment webhook received for order with existing successful payment` - Order already has successful payment record
- `Order cancelled due to payment failure` - Payment failed, order and hold cancelled, stock returned
- `Payment webhook processing failed` - General error during webhook processing

**Hold Expiry:**
- `Expired hold processed` - Individual hold processed and stock released
- `Expired holds processing completed` - Batch processing summary with processed_count and released_quantity

### Metrics to Monitor

**Performance Metrics:**
- **Deadlock Rate**: Count occurrences of `error_type: deadlock_contention` in logs
- **Webhook Deduplication Rate**: Count `metric_type: webhook_deduplication` events
- **Idempotency Key Mismatches**: Count `metric_type: idempotency_key_mismatch` warnings
- **Stock Contention**: Count `Insufficient stock for hold` warnings
- **Cache Hit Rate**: Monitor `Order retrieved from cache` vs total order requests
- **Payment Retry Success Rate**: Count `Order marked as paid after previous cancellation` vs total cancelled orders

**Business Metrics:**
- **Hold Success Rate**: Successful holds vs total hold attempts
- **Order Conversion Rate**: Orders created vs holds created
- **Payment Success Rate**: Successful payments vs total orders
- **Stock Release Rate**: Quantity released from expired holds

**Error Metrics:**
- **Transaction Failures**: Count of `failed` log entries with error_type
- **Retry Frequency**: Monitor deadlock retry patterns
- **Webhook Errors**: Failed webhook processing attempts
- **Duplicate Payment Attempts**: Count `Payment webhook received for already paid order` warnings
- **Invalid Payment Attempts**: Count attempts to pay orders with existing successful payments

## API Endpoints

**GET** `/api/products/{id}` - Get product with real-time stock  
**POST** `/api/holds` - Create hold: `{product_id, qty}` → `{hold_id, expires_at}`  
**POST** `/api/orders` - Create order: `{hold_id}` → `{order_id, product_id, quantity, total_price_cents, status}`  
**POST** `/api/payments/webhook` - Payment webhook: `{order_id, idempotency_key, success}` → `{message, order_id, status}`

## Architecture Highlights

- **Atomic Stock Updates**: `WHERE stock >= quantity` check and decrement in single operation
- **Row-Level Locking**: `lockForUpdate()` prevents race conditions
- **Transaction Retries**: Automatic 5-retry for deadlock handling
- **Background Processing**: `ProcessExpiredHolds` job runs every minute via scheduler
- **Database Indexes**: Composite index on `holds(product_id, status, expires_at)`
