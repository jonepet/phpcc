// expect: 42
template<typename T>
struct Box {
    T value;

    T get() {
        return value;
    }
};

int main() {
    Box<int>* b = new Box<int>();
    b->value = 42;
    int result = b->get();
    delete b;
    return result;
}
