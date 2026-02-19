// expect: 42
struct Vec2 {
    int x;
    int y;

    int dot(Vec2* other) {
        return x * other->x + y * other->y;
    }

    int lengthSq() {
        return x * x + y * y;
    }
};

struct Counter {
    int value;

    void add(int n) {
        value = value + n;
    }

    int get() {
        return value;
    }
};

int main() {
    Vec2* a = new Vec2();
    a->x = 3;
    a->y = 4;

    Vec2* b = new Vec2();
    b->x = 1;
    b->y = 2;

    int d = a->dot(b);
    int l = a->lengthSq();

    Counter* c = new Counter();
    c->value = 0;
    c->add(d);
    c->add(l);
    c->add(6);

    int result = c->get();

    delete a;
    delete b;
    delete c;

    return result;
}
