<?php

namespace OsarisUk\Cart\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;

const STATUS_ACTIVE = 'active';
const STATUS_PENDING = 'pending';
const STATUS_EXPIRED = 'expired';
const STATUS_COMPLETE = 'complete';

/**
 * Class Cart
 * @package OsarisUk\Cart\Models
 */
class Cart extends Model
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'cart';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'session',
        'name',
        'status',
        'total_price',
        'item_count',
        'placed_at',
        'completed_at'
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'placed_at',
        'completed_at'
    ];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [
        'total_price' => 'float',
        'item_count' => 'int',
    ];

    /**
    * Get the user that owns the cart.
    * @codeCoverageIgnore
    */
    public function user()
    {
        return $this->belongsTo(config('cart.user_model'));
    }

    /**
    * Get the items for the cart.
    */
    public function items()
    {
        return $this->hasMany(config('cart.cart_line_model'));
    }

    /**
     * @param $query
     * @return mixed
     */
    public function scopePending($query)
    {
        return $query->where('status', STATUS_PENDING);
    }

    /**
     * @param $query
     * @return mixed
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', STATUS_COMPLETE);
    }

    /**
     * @param $query
     * @return mixed
     */
    public function scopeExpired($query)
    {
        return $query->where('status', STATUS_EXPIRED);
    }

    /**
     * @param $query
     * @return mixed
     */
    public function scopeActive($query)
    {
        return $query->where('status', STATUS_ACTIVE);
    }

    /**
     * @param $query
     * @param string $instance_name
     * @return mixed
     */
    public function scopeInstance($query, $instance_name = 'default')
    {
        return $query->where('name',  $instance_name);
    }

    /**
     * @param $query
     * @param null $user_id
     * @return mixed
     */
    public function scopeUser($query, $user_id = null)
    {
        $user_id = $user_id ?: config('cart.user_id');
        if ($user_id instanceof \Closure) {
            $user_id = $user_id();
        }
        return $query->where('user_id', $user_id);
    }

    /**
     * @param $query
     * @param null $session_id
     * @return mixed
     */
    public function scopeSession($query, $session_id = null)
    {
        $session_id = $session_id ?: app('request')->session()->getId();
        return $query->where('session', $session_id);
    }

    /**
     * @param $value
     */
    public function setTotalPriceAttribute($value)
    {
        $this->attributes['total_price'] = $value;
    }

    /**
     * Get the current cart instance
     *
     * @param  string  $instance_name
     * @return mixed
     */
    public static function current($instance_name = 'default', $save_on_demand = null)
    {
        $save_on_demand = is_null($save_on_demand)? config('cart.save_on_demand', false): $save_on_demand;
        return static::init($instance_name, $save_on_demand);
    }

    /**
     * Initialize the cart
     *
     * @param  string  $instance_name
     * @return mixed
     */
    public static function init($instance_name, $save_on_demand)
    {
        $request = app('request');
        $session_id = $request->session()->getId();
        $user_id = config('cart.user_id');
        $app = Application::getInstance();
        $carts = $app->offsetGet("cart_instances");

        if ($user_id instanceof \Closure) {
            $user_id = $user_id();
        }

        //if user logged in
        if ($user_id) {
            $user_cart = static::active()->user()->where('name', $instance_name)->first();

            $session_cart_id = $request->session()->get('cart_'.$instance_name);
            $session_cart = is_null($session_cart_id)? null: static::active()->session($session_cart_id)->where('name', $instance_name)->first();

            switch (true) {

                case is_null($user_cart) && is_null($session_cart): //no user cart or session cart
                    $attributes = array(
                        'user_id' => $user_id,
                        'name' => $instance_name,
                        'status' => STATUS_ACTIVE
                    );
                    if($save_on_demand)
                        $cart = new static($attributes);
                    else
                        $cart = static::create($attributes);

                    break;

                case !is_null($user_cart) && is_null($session_cart): //only user cart
                    $cart = $user_cart;
                    break;

                case is_null($user_cart) && !is_null($session_cart): //only session cart
                    $cart = $session_cart;
                    $cart->user_id = $user_id;
                    $cart->session = null;
                    $cart->save();
                    break;

                case !is_null($user_cart) && !is_null($session_cart): //both user cart and session cart exists
                    $session_cart->moveItemsTo($user_cart); //move items from session cart to user cart
                    $session_cart->delete(); //delete session cart
                    $cart = $user_cart;
                    break;
            }

            $request->session()->forget('cart_'.$instance_name); //no longer need it.
            $carts[$instance_name] = $cart;
        } else {
            //guest user, create cart with session id
            $attributes = array(
                'session' => $session_id,
                'name' => $instance_name,
                'status' => STATUS_ACTIVE
            );
            $cart = static::firstOrNew($attributes);

            if(!$save_on_demand)
                $cart->save();

            //save current session id, since upon login session id will be regenerated
            //we will use this id to get back the cart before login
            $request->session()->put('cart_'.$instance_name, $session_id);
            $carts[$instance_name] = $cart;
        }
        $app->offsetSet("cart_instances", $carts);
        return $carts[$instance_name];
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Cart $cart) {
            $cart->items->delete();
        });
    }

    /**
     * Add item to a cart. Increases quantity if the item already exists.
     *
     * @param array $attributes
     * @return mixed
     */
    public function addItem(array $attributes = [])
    {
        if ($item = $this->getItem(collect($attributes)->except(['quantity']))) {
            $item->quantity += $attributes['quantity'];
            $item->save();
            return $item;
        }
        return $this->items->create($attributes);
    }

    /**
     * remove item from a cart
     *
     * @param array $attributes
     * @return
     */
    public function removeItem(array $attributes = [])
    {
        $item =  $this->items->where($attributes)->first();

        if ($item) {
            return $item->delete();
        } else {
            return false;
        }
    }

    /**
     * update item in a cart
     *
     * @param array $where
     * @param array $values
     * @return bool
     */
    public function updateItem(array $where, array $values)
    {
        $item =  $this->items->where($where)->first();

        if ($item) {
            return $item->update($values);
        } else {
            return false;
        }
    }


    /**
     * Cart checkout.
     *
     */
    public function checkout()
    {
        return $this->update(
            [
                'status' => STATUS_PENDING,
                'placed_at' => Carbon::now()
            ]
        );
    }

    /**
     * Expires a cart
     *
     */
    public function expire()
    {
        return $this->update(
            [
                'status' => STATUS_EXPIRED
            ]
        );
    }

    /**
     * Set a cart as complete
     *
     */
    public function complete()
    {
        return $this->update(
            [
                'status' => STATUS_COMPLETE,
                'completed_at' => Carbon::now()
            ]
        );
    }

    /**
     * Check if cart is empty
     *
     */
    public function isEmpty()
    {
        return empty($this->item_count);
    }

    /**
     * @param $where
     * @return mixed
     */
    public function getItem($where)
    {
        if ($where instanceof Collection) {
            $where = $where->toArray();
        }

        return $this->items->where($where)->first();
    }

    /**
     * @param $where
     * @return bool
     */
    public function hasItem($where)
    {
        return !is_null($this->getItem($where));
    }

    /**
     * Empties a cart
     *
     */
    public function clear()
    {
        $this->items->delete();
        $this->updateTimestamps();
        $this->total_price = 0;
        $this->item_count = 0;
        $this->relations = [];
        return $this->save();
    }

    /**
     * Move Items to another cart instance
     *
     * @param Cart $cart
     * @return Cart
     */
    public function moveItemsTo(Cart $cart)
    {
        \DB::transaction(function () use(&$cart){
            $current_items = $cart->items->pluck('product_id');
            $items_to_move = $this->items->whereNotIn('product_id', $current_items->toArray())->get();

            if ($items_to_move->count() === 0) {
                return;
            }

            $this->items->whereNotIn('product_id', $current_items->toArray())->update([
                'cart_id' => $cart->id
            ]);

            foreach ($items_to_move as $item) {
                $this->item_count -= $item->quantity;
                $this->total_price -= $item->getPrice();
                $cart->item_count += $item->quantity;
                $cart->total_price += $item->getPrice();
            }

            $this->relations = [];
            $cart->relations = [];
            $this->save();
            $cart->save();
        });

        return $cart;
    }

    /**
     * Refresh cart based on cart line items.
     *
     * @return mixed
     */
    public function refresh()
    {
        $this->updateTimestamps();
        $this->total_price = $this->items->sum('unit_price');
        $this->item_count = $this->items->sum('quantity');
        $this->relations = [];
        return $this->save();
    }
}

