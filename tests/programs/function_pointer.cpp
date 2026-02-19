// expect: 15
// toolchain: true
int add(int a, int b) {
    return a + b;
}

int mul(int a, int b) {
    return a * b;
}

int apply(int (*fn)(int, int), int x, int y) {
    return fn(x, y);
}

int main() {
    int sum = apply(add, 5, 10);
    return sum;
}
