// expect: 120
int main() {
    int x = 42;
    int* p = &x;
    *p = 100;

    int arr[5];
    arr[0] = 10;
    arr[1] = 20;
    arr[2] = 30;

    return x + arr[1];  // 120
}
