// expect: 30
struct Animal {
    int legs;

    virtual int speak() {
        return 0;
    }

    int getLegs() {
        return legs;
    }
};

struct Dog : Animal {
    int speak() {
        return 10;
    }
};

struct Cat : Animal {
    int speak() {
        return 20;
    }
};

int main() {
    Dog* d = new Dog();
    d->legs = 4;

    Cat* c = new Cat();
    c->legs = 4;

    int result = d->speak() + c->speak();

    delete d;
    delete c;

    return result;
}
