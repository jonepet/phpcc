// expect: 127
int add(int a, int b) {
    return a + b;
}

int factorial(int n) {
    if (n <= 1) {
        return 1;
    }
    return n * factorial(n - 1);
}

int main() {
    int x = add(3, 4);      // 7
    int y = factorial(5);    // 120
    return x + y;            // 127
}
