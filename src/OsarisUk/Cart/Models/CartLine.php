<?php

namespace OsarisUk\Cart\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class CartLine
 * @package OsarisUk\Cart\Models
 */
class CartLine extends Model
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'cart_lines';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'cart_id',
        'product_id',
        'attributes',
        'quantity',
        'unit_price'
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [
        'cart_id' => 'int',
        'product_id' => 'int',
        'attributes' => 'array',
        'quantity' => 'int',
        'unit_price' => 'float',
    ];

    /**
    * Get the product record associated with the item.
    * @codeCoverageIgnore
    */
    public function product()
    {
        return $this->belongsTo(config('cart.product_model'), 'product_id');
    }

    /**
    * Get the cart that owns the item.
    */
    public function cart()
    {
        return $this->belongsTo(config('cart.cart_model'), 'cart_id');
    }

    /**
     * Get Item original quantity before update
     *
     * @return mixed
     */
    public function getOriginalQuantity()
    {
        return $this->original['quantity'];
    }

    /**
     * Get Item original price before update
     *
     * @return mixed
     */
    public function getOriginalUnitPrice()
    {
        return $this->original['unit_price'];
    }

    /**
     * Get Item price
     *
     * @return float|int
     */
    public function getPrice()
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * Get Item original price before update
     *
     * @return float|int
     */
    public function getOriginalPrice()
    {
        return $this->getOriginalQuantity() * $this->getOriginalUnitPrice();
    }

    /**
     * Get the singleton cart of this line item.
     *
     * @return |null
     */
    public function getCartInstance()
    {
        $carts = app('cart_instances');

        foreach ($carts as $name => $cart) {
            if($cart->id === $this->cart_id){
                return $cart;
            }
        }

        return null;
    }

    /**
     * Move this item to another cart
     *
     * @param Cart $cart
     * @return |null
     */
    public function moveTo(Cart $cart)
    {
        $model = null;

        \DB::transaction(function () use($cart, &$model) {
            $this->delete();
            $attr = $this->attributes;
            unset($attr['cart_id']);
            $model = $cart->items()->create($attr);
        });

        return $model;
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function (CartLine $line) {
            $cart = $line->getCartInstance() ?: $line->cart;
            $cart->total_price = $cart->total_price + $line->getPrice();
            $cart->item_count = $cart->item_count + $line->quantity;
            $cart->relations = [];
            $cart->save();
        });

        static::updated(function (CartLine $line) {
            $cart = $line->getCartInstance() ?: $line->cart;
            $cart->total_price = $cart->total_price - $line->getOriginalPrice() + $line->getPrice();
            $cart->item_count = $cart->item_count - $line->getOriginalQuantity() + $line->quantity;
            $cart->relations = [];
            $cart->save();
        });

        static::deleted(function (CartLine $line) {
            $cart = $line->getCartInstance() ?: $line->cart;
            $cart->total_price = $cart->total_price - $line->getPrice();
            $cart->item_count = $cart->item_count - $line->quantity;
            $cart->relations = [];
            $cart->save();
        });
    }
}

