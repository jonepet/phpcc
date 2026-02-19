// expect: 4
int main() {
    int a = 10;
    int b = 3;
    int c = a + b * 2;    // 16
    int d = c - a;         // 6
    int e = d / 2;         // 3
    int f = c % 5;         // 1
    return e + f;          // 4
}
