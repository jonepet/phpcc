// expect: 7
// toolchain: true
extern "C" {
    int printf(const char* fmt, ...);
}

typedef int (*BinOp)(int, int);

int add(int a, int b) {
    return a + b;
}

int mul(int a, int b) {
    return a * b;
}

int apply(BinOp fn, int x, int y) {
    return fn(x, y);
}

int main() {
    BinOp op = add;
    int sum = apply(op, 3, 4);
    printf("sum=%d\n", sum);
    return sum;
}
